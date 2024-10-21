<?php

require_once __DIR__ . '/../class-pst-keyring-gmail-service.php';
class Test_Keyring_Gmail_Service extends WP_UnitTestCase {


	private $count_chunks = 0;

	private $expected_ids = array();

	protected function tearDown(): void {
		parent::tearDown();
		wp_set_current_user( 0 );
		$this->count_chunks = 0;
		$this->expected_ids = array();
	}

	public function test_admin_can_get_messages_with_no_query_params() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service         = $this->mock_keyring_gmail_connection();
		$max_results           = Pst_Keyring_Gmail_Service::MAX_RESULTS;
		$this->expected_ids    = $this->generateMessagesIDs( $max_results );
		$this->expected_ids[0] = array( 'id' => '123' );
		add_filter( 'pre_http_request', array( $this, 'filter_batch_callback' ), 10, 3 );

		$gmail_service->expects( $this->once() )
			->method( 'request' )
			->with( Pst_Keyring_Gmail_Service::API_URL . "messages?maxResults=$max_results" )
			->willReturn( (object) array( 'messages' => $this->expected_ids ) );

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertNotWPError( $response );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'messages', 'missingMessages', 'nextPageToken', 'resultSize', 'errorMessage' ), array_keys( $response->get_data() ) );
		$this->assertEquals( $max_results, count( $response->get_data()['messages'] ), 'Numbers are not close enough' );
		$this->assertEquals(
			$response->get_data()['messages'][0],
			array(
				'id'           => '123',
				'threadId'     => '123',
				'labelIds'     => array( 'UNREAD', 'INBOX' ),
				'snippet'      => 'Snippet for message 123',
				'historyId'    => '123',
				'internalDate' => '1726511938000',
				'sizeEstimate' => 93183,
				'payload'      => array(
					'headers' => array(),
				),
				'content'      => 'Content for message 123',
				'createdAt'    => '2024-09-16 18:38:58',
			)
		);
	}

	public function filter_batch_callback() {
		$chunks        = array_chunk( array_column( $this->expected_ids, 'id' ), Pst_Keyring_Gmail_Service::BATCH_MAX_SIZE );
		$current_chunk = $chunks[ $this->count_chunks ];
		if ( ( count( $chunks ) - 1 ) === $this->count_chunks ) {
			remove_filter( 'pre_http_request', array( $this, 'filter_batch_callback' ), 10, 3 );
		}
		$this->count_chunks++;
		return $this->generate_batch_response( $current_chunk );
	}

	public function test_admin_can_get_messages_with_all_query_params() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service         = $this->mock_keyring_gmail_connection();
		$max_results           = 100;
		$this->expected_ids    = $this->generateMessagesIDs( $max_results );
		$this->expected_ids[0] = array( 'id' => '123' );
		add_filter( 'pre_http_request', array( $this, 'filter_batch_callback' ), 10, 3 );

		$expected_query = "maxResults=$max_results&pageToken=next_page_token&q=test&labelIds%5B0%5D=INBOX&includeSpamTrash=1";

		$gmail_service->expects( $this->once() )
			->method( 'request' )
			->with( Pst_Keyring_Gmail_Service::API_URL . 'messages?' . $expected_query )
			->willReturn( (object) array( 'messages' => $this->expected_ids ) );

		// When.
		$request = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$request->set_query_params(
			array(
				'maxResults'       => $max_results,
				'pageToken'        => 'next_page_token',
				'q'                => 'test',
				'labelIds'         => array( 'INBOX' ),
				'includeSpamTrash' => 1,
			)
		);
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertNotWPError( $response );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'messages', 'missingMessages', 'nextPageToken', 'resultSize', 'errorMessage' ), array_keys( $response->get_data() ) );
		$this->assertCount( $max_results, $response->get_data()['messages'] );
		$this->assertEquals(
			$response->get_data()['messages'][0],
			array(
				'id'           => '123',
				'threadId'     => '123',
				'labelIds'     => array( 'UNREAD', 'INBOX' ),
				'snippet'      => 'Snippet for message 123',
				'historyId'    => '123',
				'internalDate' => '1726511938000',
				'sizeEstimate' => 93183,
				'payload'      => array(
					'headers' => array(),
				),
				'content'      => 'Content for message 123',
				'createdAt'    => '2024-09-16 18:38:58',
			)
		);
	}

	public function test_admin_cant_list_messages_ids() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service = $this->mock_keyring_gmail_connection();
		$max_results   = Pst_Keyring_Gmail_Service::MAX_RESULTS;

		$gmail_service->expects( $this->atMost( Pst_Keyring_Gmail_Service::MAX_RETRY_ATTEMPTS + 1 ) )
			->method( 'request' )
			->with( Pst_Keyring_Gmail_Service::API_URL . "messages?maxResults=$max_results" )
			->willReturn( new WP_Error( 401, 'Failed to fetch messages IDs from Gmail' ) );

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'api_request_failed', $response->as_error()->get_error_code() );
	}

	public function test_admin_list_messages_with_empty_message_ids() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service = $this->mock_keyring_gmail_connection();
		$max_results   = Pst_Keyring_Gmail_Service::MAX_RESULTS;

		$gmail_service->expects( $this->atMost( Pst_Keyring_Gmail_Service::MAX_RETRY_ATTEMPTS ) )
			->method( 'request' )
			->with( Pst_Keyring_Gmail_Service::API_URL . "messages?maxResults=$max_results" )
			->willReturn( (object) array() );

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertNotWPError( $response );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'messages', 'missingMessages', 'nextPageToken', 'resultSize', 'errorMessage' ), array_keys( $response->get_data() ) );
		$this->assertCount( 0, $response->get_data()['messages'] );
		$this->assertEquals( $response->get_data()['messages'], array() );
	}

	public function test_admin_get_messages_with_fetch_full_messages_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service   = $this->mock_keyring_gmail_connection();
		$max_results     = Pst_Keyring_Gmail_Service::MAX_RESULTS;
		$expected_ids    = $this->generateMessagesIDs( $max_results );
		$expected_ids[0] = array( 'id' => '123' );
		$batch_callback  = function () {
			return new WP_Error( 401, 'Failed to fetch batch messages.' );
		};
		add_filter( 'pre_http_request', $batch_callback, 10, 3 );

		$gmail_service->expects( $this->once() )
			->method( 'request' )
			->with( Pst_Keyring_Gmail_Service::API_URL . "messages?maxResults=$max_results" )
			->willReturn( (object) array( 'messages' => $expected_ids ) );

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'batch_request_failed', $response->as_error()->get_error_code() );
	}

	public function test_editor_cant_get_messages() {
		// Given.
		$editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_user_id );

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	public function test_admin_get_messages_with_keyring_not_installed_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		global $pst_keyring_gmail_service;
		$pst_keyring_gmail_service = null;

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'keyring_not_installed', $response->as_error()->get_error_code() );
	}

	public function test_admin_get_messages_with_gmail_connection_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$this->mock_keyring_gmail_connection( false );

		// When.
		$request  = new WP_REST_Request( 'GET', '/pistachio/v1/gmail/messages' );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'gmail_not_connected', $response->as_error()->get_error_code() );
	}

	public function test_admin_can_mark_messages_as_imported() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service     = $this->mock_keyring_gmail_connection();
		$imported_label_id = 'label_abc123';
		$message_ids       = array( '191e8006bde5507d' );

		$gmail_service->expects( $this->exactly( 2 ) )
			->method( 'request' )
			->withConsecutive(
				array( $this->equalTo( Pst_Keyring_Gmail_Service::API_URL . 'labels' ) ),
				array(
					$this->equalTo( Pst_Keyring_Gmail_Service::API_URL . 'messages/batchModify' ),
					array(
						'method'  => 'POST',
						'headers' => array( 'Content-Type' => 'application/json' ),
						'body'    => json_encode(
							array(
								'ids'         => $message_ids,
								'addLabelIds' => array( $imported_label_id ),
							)
						),
					),
				)
			)
			->willReturnOnConsecutiveCalls(
				(object) array(
					'labels' => array(
						(object) array(
							'name' => Pst_Keyring_Gmail_Service::IMPORTED_LABEL_NAME,
							'id'   => $imported_label_id,
						),
					),
				),
				(object) array()
			);

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', $message_ids );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertNotWPError( $response );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( (object) array(), $response->get_data() );
	}

	public function test_editor_cant_mark_messages_as_imported() {
		// Given.
		$editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_user_id );

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', array( '123' ) );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'rest_forbidden', $response->as_error()->get_error_code() );
	}

	public function test_admin_cant_mark_messages_as_imported_with_invalid_ids() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$invalid_message_ids = '123 456';

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', $invalid_message_ids );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'rest_invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_admin_mark_messages_as_imported_with_keyring_not_installed_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		global $pst_keyring_gmail_service;
		$pst_keyring_gmail_service = null;

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', array( '191e8006bde5507d' ) );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'keyring_not_installed', $response->as_error()->get_error_code() );
	}

	public function test_admin_mark_messages_as_imported_with_gmail_connection_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$this->mock_keyring_gmail_connection( false );

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', array( '191e8006bde5507d' ) );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'gmail_not_connected', $response->as_error()->get_error_code() );
	}

	public function test_admin_mark_messages_as_imported_with_get_imported_label_id_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service = $this->mock_keyring_gmail_connection();

		$gmail_service->expects( $this->once() )
			->method( 'request' )
			->with( $this->equalTo( Pst_Keyring_Gmail_Service::API_URL . 'labels' ) )
			->willReturn( new WP_Error( 401, 'Failed to fetch labels.' ) );

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', array( '191e8006bde5507d' ) );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'get_labels_failed', $response->as_error()->get_error_code() );
	}

	public function test_admin_mark_messages_as_imported_with_imported_label_not_found() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service = $this->mock_keyring_gmail_connection();

		$gmail_service->expects( $this->once() )
			->method( 'request' )
			->with( $this->equalTo( Pst_Keyring_Gmail_Service::API_URL . 'labels' ) )
			->willReturn( (object) array() );

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', array( '191e8006bde5507d' ) );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'label_not_found', $response->as_error()->get_error_code() );
	}

	public function test_admin_mark_messages_as_imported_with_batch_error() {
		// Given.
		$admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );
		$gmail_service     = $this->mock_keyring_gmail_connection();
		$imported_label_id = 'label_abc123';
		$message_ids       = array( '191e8006bde5507d' );

		$gmail_service->expects( $this->exactly( 2 ) )
			->method( 'request' )
			->withConsecutive(
				array( $this->equalTo( Pst_Keyring_Gmail_Service::API_URL . 'labels' ) ),
				array(
					$this->equalTo( Pst_Keyring_Gmail_Service::API_URL . 'messages/batchModify' ),
					$this->anything(),
				)
			)
			->willReturnOnConsecutiveCalls(
				(object) array(
					'labels' => array(
						(object) array(
							'name' => Pst_Keyring_Gmail_Service::IMPORTED_LABEL_NAME,
							'id'   => $imported_label_id,
						),
					),
				),
				new WP_Error( 401, 'Failed to modify messages.' )
			);

		// When.
		$request = new WP_REST_Request( 'POST', '/pistachio/v1/gmail/imported' );
		$request->set_param( 'ids', $message_ids );
		$response = rest_get_server()->dispatch( $request );

		// Then.
		$this->assertWPError( $response->as_error() );
		$this->assertEquals( 'api_request_failed', $response->as_error()->get_error_code() );
	}

	/**
	 *  Helper function to mock the Keyring Gmail service connection.
	 */
	private function mock_keyring_gmail_connection( $is_connected = true ) {
		$gmail_service = $this->getMockBuilder( 'Keyring_Service_GoogleMail' )
			->disableOriginalConstructor()
			->getMock();

		$gmail_service->expects( $this->atLeast( 1 ) )
			->method( 'is_connected' )
			->willReturn( $is_connected );

		if ( $is_connected ) {
			$access_token = $this->getMockBuilder( 'Keyring_Access_Token' )
				->disableOriginalConstructor()
				->getMock();

			$gmail_service->expects( $this->atLeast( 1 ) )
				->method( 'get_tokens' )
				->willReturn( array( $access_token ) );

			$gmail_service->expects( $this->atLeast( 1 ) )
				->method( 'set_token' )
				->with( $this->isInstanceOf( 'Keyring_Access_Token' ) );
		}

		global $pst_keyring_gmail_service;
		$pst_keyring_gmail_service = $gmail_service;

		return $pst_keyring_gmail_service;
	}

	/**
	 *  Helper function to generate a batch response.
	 */
	private function generate_batch_response( $message_ids ) {

		$boundary = 'batch_boundary';
		$body     = "--$boundary\r\n";
		foreach ( $message_ids as $id ) {
			$body .= "Content-Type: application/http\r\n\r\n";
			$body .= "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
			$body .= json_encode(
				array(
					'id'           => $id,
					'threadId'     => $id,
					'labelIds'     => array( 'UNREAD', 'INBOX' ),
					'snippet'      => "Snippet for message $id",
					'historyId'    => $id,
					'internalDate' => '1726511938000',
					'sizeEstimate' => 93183,
					'payload'      => array(
						'headers' => array(),
						'body'    => array( 'data' => base64_encode( "Content for message $id" ) ),
					),
				)
			);
			$body .= "\r\n--$boundary\r\n";
		}

		$body .= "--$boundary--";
		return array(
			'headers'  => array( 'content-type' => "multipart/mixed; boundary=$boundary" ),
			'body'     => $body,
			'response' => array( 'code' => 200 ),
		);
	}

	private function generateMessagesIDs( $max_results ) {
		$ids = array();
		for ( $i = 0; $i < $max_results; $i++ ) {
			$ids[] = array( 'id' => $i . uniqid() );
		}
		return $ids;
	}
}
