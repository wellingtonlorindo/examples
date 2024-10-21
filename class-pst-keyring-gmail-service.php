<?php

/**
 * Class Pst_Keyring_Gmail_Service
 *
 * Handles interactions with the Gmail API using the Keyring authentication plugin.
 */
class Pst_Keyring_Gmail_Service {



	public const API_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/';

	public const BATCH_ENDPOINT = 'https://gmail.googleapis.com/batch';

	public const IMPORTED_LABEL_NAME = 'IMPORTED';

	public const MAX_RESULTS = 300;

	public const BATCH_MAX_SIZE = 40;

	public const MAX_RETRY_ATTEMPTS = 40;

	// 0.5 second (in microseconds)
	private const RATE_LIMIT_DELAY = 500000;

	private const OK_HTTP_STATUS = 200;

	private const PARTIAL_CONTENT_HTTP_STATUS = 206;

	private const LOW_PRIORITY = 11;

	/**
	 * Stores fetched full messages indexed by their IDs.
	 *
	 * @var array
	 */
	private $fetched_full_messages = array();

	/**
	 * Registers actions and filters for the Gmail service.
	 */
	public function __construct() {
		add_action( 'keyring_load_services', array( $this, 'load_keyring_gmail_service' ), self::LOW_PRIORITY );
		add_filter( 'keyring_google-mail_request_token_params', array( $this, 'set_scope_on_gmail_token_params' ), self::LOW_PRIORITY );
	}

	/**
	 * Fetches full messages from Gmail based on the given filter.
	 *
	 * @param WP_REST_Request $request The REST request object containing query parameters.
	 * @return array|WP_Error An array of messages and metadata, or WP_Error on failure.
	 */
	public function fetch_full_messages_with_filter( WP_REST_Request $request ) {
		$this->fetched_full_messages = array();
		$gmail_connection            = $this->setup_gmail_service_connection();
		if ( is_wp_error( $gmail_connection ) ) {
			return $gmail_connection;
		}

		$query_params          = $this->build_query_params( $request );
		$messages_ids_response = $this->fetch_message_ids( $query_params );
		if ( is_wp_error( $messages_ids_response ) ) {
			return $messages_ids_response;
		}

		if ( empty( $messages_ids_response->messages ) ) {
			return array(
				'messages'         => array(),
				'missingMessages' => array(),
				'nextPageToken'    => null,
				'resultSize'       => 0,
				'errorMessage'    => null,
			);
		}

		$messages_ids           = array_column( $messages_ids_response->messages, 'id' );
		$full_messages_response = $this->fetch_full_messages( $messages_ids );
		$fetched_messages       = array_values( $this->fetched_full_messages );
		if ( is_wp_error( $full_messages_response ) ) {

			if ( 'partial_content' !== $full_messages_response->get_error_code() ) {
				return $full_messages_response;
			}

			return new WP_REST_Response(
				array(
					'messages'         => $fetched_messages,
					'missingMessages' => array_values( array_diff( $messages_ids, array_column( $fetched_messages, 'id' ) ) ),
					'nextPageToken'    => null,
					'resultSize'       => count( $fetched_messages ),
					'errorMessage'    => $full_messages_response->get_error_message( 'partial_content' ),
				),
				self::PARTIAL_CONTENT_HTTP_STATUS
			);
		}

		return array(
			'messages'         => $fetched_messages,
			'missingMessages' => array(),
			'nextPageToken'    => $messages_ids_response->nextPageToken ?? null,
			'resultSize'       => count( $fetched_messages ),
			'errorMessage'    => null,
		);
	}

	/**
	 * Marks specified messages as imported in Gmail.
	 *
	 * @param WP_REST_Request $request The REST request object containing message IDs.
	 * @return array|WP_Error The API response or WP_Error on failure.
	 */
	public function mark_messages_as_imported( WP_REST_Request $request ) {
		$message_ids = $request->get_param( 'ids' );

		$gmail_connection = $this->setup_gmail_service_connection();
		if ( is_wp_error( $gmail_connection ) ) {
			return $gmail_connection;
		}

		$imported_label_id = $this->get_imported_label_id();
		if ( is_wp_error( $imported_label_id ) ) {
			return $imported_label_id;
		}

		$response = $this->request_with_retry(
			self::API_URL . 'messages/batchModify',
			array(
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => json_encode(
					array(
						'ids'         => array_unique( $message_ids ),
						'addLabelIds' => array( $imported_label_id ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_request_failed', __( 'Failed to mark Gmail messages as imported.', 'pistachio' ) );
		}

		return $response;
	}

	/**
	 * Loads the Gmail service via Keyring plugin.
	 */
	public function load_keyring_gmail_service() {
		if ( class_exists( 'Keyring' ) ) {
			global $pst_keyring_gmail_service;
			$pst_keyring_gmail_service = Keyring::get_service_by_name( 'google-mail' );
		}
	}

	/**
	 * Sets the proper scope for Gmail API requests.
	 *
	 * @param array $params The existing token parameters.
	 * @return array The modified token parameters with the correct scope.
	 */
	public function set_scope_on_gmail_token_params( $params ) {
		$params['scope'] = 'https://www.googleapis.com/auth/gmail.modify';
		return $params;
	}

	/**
	 * Sets up the Gmail service connection.
	 *
	 * @return bool|WP_Error True if connection is successful, WP_Error otherwise.
	 */
	private function setup_gmail_service_connection() {
		 $token = $this->fetch_access_token_with_validation();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$this->get_service()->set_token( $token );
		$this->get_service()->maybe_refresh_token();

		return true;
	}

	/**
	 * Fetches and validates the access token for Gmail API.
	 *
	 * @return Keyring_Access_Token|WP_Error The access token if valid, WP_Error otherwise.
	 */
	private function fetch_access_token_with_validation() {
		if ( empty( $this->get_service() ) ) {
			return new WP_Error( 'keyring_not_installed', __( 'Keyring plugin is not active', 'pistachio' ) );
		}

		if ( ! $this->get_service()->is_connected() ) {
			return new WP_Error( 'gmail_not_connected', __( 'Gmail service is not connected', 'pistachio' ) );
		}

		$token = current( $this->get_service()->get_tokens() );
		if ( is_wp_error( $token ) || empty( $token ) ) {
			return new WP_Error( 'gmail_invalid_token', __( 'Gmail invalid token', 'pistachio' ) );
		}

		return $token;
	}

	/**
	 * Makes a request to the Gmail API with fixed backoff for rate limiting.
	 *
	 * @param string $url The API endpoint URL.
	 * @param array  $params The request parameters.
	 * @return array|WP_Error The API response or WP_Error on failure.
	 */
	private function request_with_retry( string $url, $params = array() ) {
		$retry_attempts = self::MAX_RETRY_ATTEMPTS;

		while ( true ) {
			$response = $this->get_service()->request( $url, $params );
			if ( is_wp_error( $response ) ) {
				$status_code = wp_remote_retrieve_response_code( $response );
				$status_code = empty( $status_code ) ? $response->get_error_code() : $status_code;
				if ( $this->is_retriable_error( $status_code ) && $retry_attempts > 0 ) {
					$this->apply_rate_limit_delay();
					$retry_attempts--;
					continue;
				}
				return $response;
			}

			return $response;
		}
	}

	/**
	 * Determines if an error is retriable based on its HTTP status code.
	 *
	 * @link https://developers.google.com/gmail/api/guides/handle-errors
	 *
	 * @param int|string $status_code The HTTP status code to check.
	 * @return bool True if the error is retriable, false otherwise.
	 */
	private function is_retriable_error( $status_code ): bool {
		return ! is_numeric( $status_code ) || empty( $status_code ) || in_array( $status_code, array( 429, 403, 400 ) ) || ( $status_code >= 500 && $status_code < 600 );
	}

	/**
	 * Applies a delay to respect rate limiting.
	 *
	 * @return void
	 */
	private function apply_rate_limit_delay(): void {
		usleep( self::RATE_LIMIT_DELAY );
	}

	/**
	 * Builds query parameters for the Gmail API request.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return array The constructed query parameters.
	 */
	private function build_query_params( WP_REST_Request $request ) {
		$params = array(
			'maxResults' => $request->get_param( 'maxResults' ) ?? self::MAX_RESULTS,
		);

		if ( $request->get_param( 'pageToken' ) ) {
			$params['pageToken'] = $request->get_param( 'pageToken' );
		}

		if ( $request->get_param( 'q' ) ) {
			$params['q'] = $request->get_param( 'q' );
		}

		if ( $request->get_param( 'labelIds' ) ) {
			$params['labelIds'] = $request->get_param( 'labelIds' );
		}

		if ( $request->get_param( 'includeSpamTrash' ) ) {
			$params['includeSpamTrash'] = $request->get_param( 'includeSpamTrash' );
		}

		return $params;
	}

	/**
	 * Fetches message IDs from Gmail API.
	 *
	 * @param array $query_params The query parameters for the API request.
	 * @return object|WP_Error The API response object or WP_Error on failure.
	 */
	private function fetch_message_ids( array $query_params ) {
		$gmail_api_url = self::API_URL . 'messages?' . http_build_query( $query_params );

		$response = $this->request_with_retry( $gmail_api_url );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_request_failed', __( 'Failed to fetch messages IDs from Gmail. Please try again.', 'pistachio' ) );
		}

		return $response;
	}

	/**
	 * Adds fetched full messages to the internal storage.
	 *
	 * @param array $full_messages An array of full message objects from Gmail API.
	 * @return array The updated array of fetched full messages.
	 */
	private function add_fetched_full_messages( array $full_messages ) {
		foreach ( $full_messages as $message ) {
			$this->fetched_full_messages[ $message['id'] ] = $message;
		}

		return $this->fetched_full_messages;
	}

	/**
	 * Fetches full messages for given message IDs.
	 *
	 * @param array $message_ids An array of message IDs to fetch.
	 * @param int   $retry_attempts Number of retry attempts left (to prevent infinite recursion).
	 * @return array|WP_Error An array of full messages or WP_Error on failure.
	 */
	private function fetch_full_messages( array $message_ids, int $retry_attempts = self::MAX_RETRY_ATTEMPTS ) {
		$chunks        = array_chunk( $message_ids, self::BATCH_MAX_SIZE );
		$full_messages = array();

		foreach ( $chunks as $chunk ) {
			$batch_result = $this->fetch_messages_batch( $chunk );

			if ( is_wp_error( $batch_result ) ) {
				return $batch_result;
			}

			$full_messages = array_merge( $full_messages, $batch_result );
		}

		$this->add_fetched_full_messages( $full_messages );

		// Check if all messages were fetched
		if ( count( $full_messages ) < count( $message_ids ) ) {
			$message_ids_missing = array_diff( $message_ids, array_column( $full_messages, 'id' ) );

			// Return partial content.
			if ( 0 === $retry_attempts && count( $this->fetched_full_messages ) > 0 ) {
				return new WP_Error( 'partial_content', 'Some messages could not be fetched after multiple retries. Please try again.' );
			}

			if ( 0 === $retry_attempts ) {
				return new WP_Error( 'fetch_full_messages_failed', 'The messages could not be fetched after multiple retries. Please try again.' );
			}

			return $this->fetch_full_messages( $message_ids_missing, $retry_attempts - 1 );
		}

		return $full_messages;
	}

	/**
	 * Fetches a batch of messages from Gmail API.
	 *
	 * @param array $chunk An array of message IDs to fetch in this batch.
	 * @return array|WP_Error An array of fetched messages or WP_Error on failure.
	 */
	private function fetch_messages_batch( array $chunk ) {
		$request_boundary = 'batch_boundary_' . wp_generate_uuid4();
		$retry_attempts   = self::MAX_RETRY_ATTEMPTS;

		while ( true ) {

			$this->setup_gmail_service_connection();
			$token = $this->fetch_access_token_with_validation();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$body = $this->create_batch_body( $chunk, $request_boundary, $token->token );

			$batch_response = wp_remote_post(
				self::BATCH_ENDPOINT,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token->token,
						'Content-Type'  => 'multipart/mixed; boundary=' . $request_boundary,
					),
					'body'    => $body,
				)
			);

			if ( is_wp_error( $batch_response ) ) {
				$status_code = wp_remote_retrieve_response_code( $batch_response );
				$status_code = empty( $status_code ) ? $batch_response->get_error_code() : $status_code;
				if ( $this->is_retriable_error( $status_code ) && $retry_attempts > 0 ) {
					$this->apply_rate_limit_delay();
					$retry_attempts--;
					continue;
				}
				return new WP_Error( 'batch_request_failed', "Failed to fetch batch messages: HTTP status $status_code. Please, try again.", array( 'status_code' => $status_code ) );
			}

			return $this->parse_batch_response( $batch_response );
		}
	}

	/**
	 * Creates the body for a batch request to Gmail API.
	 *
	 * @param array  $chunk An array of message IDs to include in the batch.
	 * @param string $request_boundary The boundary string for the multipart request.
	 * @param string $token The access token for authentication.
	 * @return string The constructed batch request body.
	 */
	private function create_batch_body( array $chunk, string $request_boundary, string $token ): string {
		$body = '';
		foreach ( $chunk as $message_id ) {
			$message_url = self::API_URL . "messages/$message_id?format=full";
			$body       .= "--$request_boundary\r\n";
			$body       .= "Content-Type: application/http\r\n\r\n";
			$body       .= "GET $message_url HTTP/1.1\r\n";
			$body       .= 'Authorization: Bearer ' . $token . "\r\n\r\n";
		}

		$body .= "--$request_boundary--\r\n";
		return $body;
	}

	/**
	 * Parses the batch response from Gmail API.
	 *
	 * @param array|WP_Error $batch_response The response from wp_remote_post.
	 * @return array|WP_Error An array of parsed messages or WP_Error on failure.
	 */
	private function parse_batch_response( $batch_response ) {
		if ( is_wp_error( $batch_response ) ) {
			return new WP_Error( 'batch_response_error', __( 'Batch request failed', 'pistachio' ), $batch_response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $batch_response );
		if ( self::OK_HTTP_STATUS !== $response_code ) {
			return new WP_Error( 'batch_invalid_response', __( 'Invalid batch response', 'pistachio' ) );
		}

		$batch_body = wp_remote_retrieve_body( $batch_response );
		if ( empty( $batch_body ) ) {
			return new WP_Error( 'batch_empty_response', __( 'Empty batch response body', 'pistachio' ) );
		}

		$content_type = wp_remote_retrieve_header( $batch_response, 'content-type' );
		$boundary     = $this->extract_boundary( $content_type );
		if ( is_wp_error( $boundary ) ) {
			return $boundary;
		}

		return $this->extract_messages( $batch_body, $boundary );
	}

	/**
	 * Extracts the boundary from the content-type header.
	 *
	 * @param string $content_type The content-type header value.
	 * @return string|WP_Error The boundary string or WP_Error if not found.
	 */
	private function extract_boundary( string $content_type ) {
		if ( ! preg_match( '/boundary=(.*)$/i', $content_type, $matches ) ) {
			return new WP_Error( 'boundary_not_found', __( 'Boundary not found in content-type', 'pistachio' ) );
		}
		return trim( $matches[1], '"' );
	}

	/**
	 * Extracts messages from the batch body.
	 *
	 * @param string $batch_body The batch response body.
	 * @param string $boundary The boundary string.
	 * @return array|WP_Error An array of parsed messages.
	 */
	private function extract_messages( string $batch_body, string $boundary ) {
		$parts         = preg_split( "/--{$boundary}(?:--)?\r?\n/", $batch_body );
		$full_messages = array();

		foreach ( $parts as $part ) {
			$message = $this->parse_message_part( $part );
			if ( is_wp_error( $message ) ) {
				return $message;
			}

			if ( null !== $message ) {
				$message['content'] = $this->extract_content( $message['payload'] );
				unset( $message['payload']['body'] );
				unset( $message['payload']['parts'] );

				$timestamp_s          = $message['internalDate'] / 1000; // Convert milliseconds to seconds.
				$message['createdAt'] = ( new DateTime( "@$timestamp_s" ) )->format( 'Y-m-d H:i:s' );

				$full_messages[] = $message;
			}
		}

		return $full_messages;
	}

	/**
	 * Parse an individual message part.
	 *
	 * @param string $part A single part of the multipart response.
	 * @return array|WP_Error|null Parsed message data, WP_Error if non-retriable error, or null if retriable.
	 */
	private function parse_message_part( string $part ) {

		// Extract HTTP status code from the response part
		preg_match( '/HTTP\/1.1 (\d{3})/', $part, $matches );
		$status_code = isset( $matches[1] ) ? intval( $matches[1] ) : null;

		if ( is_null( $status_code ) || $this->is_retriable_error( $status_code ) ) {
			return null;
		}

		if ( 200 !== $status_code ) {
			return new WP_Error( 'parse_message_part_error', "Non-retriable error encountered: HTTP status $status_code", array( 'status_code' => $status_code ) );
		}

		$json_start = strpos( $part, '{' );
		if ( false === $json_start ) {
			return null;
		}

		$json = substr( $part, $json_start );
		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $data['id'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Extract content from the message payload.
	 *
	 * @param array $payload The message payload.
	 * @return string The decoded message content.
	 */
	private function extract_content( array $payload ): string {
		$content = '';
		if ( isset( $payload['body']['data'] ) ) {
			$content .= $this->decode_body( $payload['body']['data'] );
		}

		if ( isset( $payload['parts'] ) && is_array( $payload['parts'] ) ) {
			foreach ( $payload['parts'] as $part ) {
				$content .= $this->extract_content( $part );
			}
		}

		return $content;
	}

	/**
	 * Decode the body content from base64url encoding.
	 *
	 * @param string $data The base64url encoded body data.
	 * @return string The decoded body content.
	 */
	private function decode_body( string $data ): string {
		// Replace URL-safe characters back to their original form.
		$base64  = str_replace( array( '-', '_' ), array( '+', '/' ), $data );
		$base64 .= str_repeat( '=', ( 4 - ( strlen( $base64 ) % 4 ) ) % 4 );

		$decoded = base64_decode( $base64, true );
		if ( false === $decoded ) {
			return '';
		}

		return $decoded;
	}

	/**
	 * Retrieves the Gmail service instance.
	 *
	 * @return mixed The Gmail service instance.
	 */
	private function get_service() {
		global $pst_keyring_gmail_service;
		return $pst_keyring_gmail_service;
	}

	/**
	 * Retrieves the ID of the 'IMPORTED' label from Gmail.
	 *
	 * @return string|WP_Error The ID of the 'IMPORTED' label if found, or WP_Error if not found or on API request failure.
	 */
	private function get_imported_label_id() {
		$labels_response = $this->request_with_retry( self::API_URL . 'labels' );
		if ( is_wp_error( $labels_response ) ) {
			return new WP_Error( 'get_labels_failed', __( 'Failed to fetch labels.', 'pistachio' ) );
		}

		if ( ! empty( $labels_response->labels ) ) {
			foreach ( $labels_response->labels as $label ) {
				if ( self::IMPORTED_LABEL_NAME === strtoupper( $label->name ) ) {
					return $label->id;
				}
			}
		}

		return new WP_Error( 'label_not_found', __( 'The IMPORTED label was not found.', 'pistachio' ) );
	}
}
