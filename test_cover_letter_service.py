"""
To run (from /):
pmt api.models.services.test.test_cover_letter_service.CoverLetterServiceTests
"""

import mock

from api.api_base_test import ApiBaseTest
from api.choices.cover_letter_feedback_choices import CoverLetterFeedbackChoicesEnum
from api.models import CoverLetter, Customer, ConversionEvent
from api.models.analytics_models.conversion_event import ConversionEventNameEnum
from api.models.services import cover_letter_service, resume_service
from fixpoint_sdk import ThumbsReaction
from utils import error_messages
from utils.integrations.openai import openai
from utils.integrations.openai.mock_openai_data import (
    mock_chat_completion_finish_reason_stop_response,
)


class CoverLetterServiceTests(ApiBaseTest):
    def setUp(self):
        super(ApiBaseTest, self).setUp()
        path_prefix = "api.models.services.cover_letter_service"
        self.init_honeybadger_mocks(path_prefix)

    @mock.patch("utils.integrations.fixpoint.fixpoint.create_chat_completion")
    def test_generate_cover_letter_success(self, mock_create_chat_completion):
        # Given
        mock_create_chat_completion.return_value = (
            mock_chat_completion_finish_reason_stop_response,
            {"name": "863626fe-bd39-4835-b638-796222a7c7a9"},
            {"name": "863626fe-bd39-4835-b638-796222a7c7a9"},
        )
        customer = self.given_customer()
        resume = self.given_resume(customer=customer)
        job_description_text = "Job description lorem ispsum dolor."

        cover_letter_data = {
            "resume": resume_service.convert_resume_to_str(resume),
            "job_description": job_description_text,
        }
        messages = [
            openai.create_user_message(cover_letter_service.AI_CONTEXT_MESSAGE_BEGIN),
            openai.create_user_message(cover_letter_service.AI_CONTEXT_MESSAGE_TASK),
            openai.create_user_message(str(cover_letter_data)),
            openai.create_user_message(cover_letter_service.AI_CONTEXT_MESSAGE_RETURN),
        ]

        # When
        cover_letter = cover_letter_service.generate_cover_letter(
            resume, job_description_text
        )

        # Then
        self.assertEqual(CoverLetter.objects.count(), 1)
        self.assertEqual(CoverLetter.objects.first().id, cover_letter.id)
        self.assertEqual(cover_letter.resume_id, resume.id)
        self.assertEqual(cover_letter.customer, resume.customer)
        self.assertEqual(
            str(cover_letter.fixpoint_input_log_response_name),
            "863626fe-bd39-4835-b638-796222a7c7a9",
        )
        self.assertEqual(cover_letter.job_description_text, job_description_text)
        self.assertEqual(
            cover_letter.generated_text,
            mock_chat_completion_finish_reason_stop_response.choices[0].message.content,
        )
        mock_create_chat_completion.assert_called_once_with(
            messages=messages,
            model=cover_letter_service.GPT_MODEL_IN_USE,
            max_tokens=cover_letter_service.MAX_TOKENS_CHAT_COMPLETION,
        )

    @mock.patch("utils.integrations.openai.openai.num_tokens_from_string")
    @mock.patch("utils.integrations.fixpoint.fixpoint.create_chat_completion")
    def test_generate_cover_letter_max_input_tokens_exceeded(
        self, mock_create_chat_completion, mock_num_tokens_from_string
    ):
        # Given
        resume = self.given_resume()
        long_job_description_text = "A looong job description."
        mock_num_tokens_from_string.return_value = (
            cover_letter_service.MAX_INPUT_TOKENS + 10
        )

        # When
        cover_letter = cover_letter_service.generate_cover_letter(
            resume, long_job_description_text
        )

        # Then
        self.assertEqual(CoverLetter.objects.count(), 0)
        self.assertEqual(cover_letter, False)
        mock_create_chat_completion.assert_not_called()

    @mock.patch("utils.integrations.fixpoint.fixpoint.create_chat_completion")
    def test_generate_cover_letter_unknown_error(self, mock_create_chat_completion):
        # Given
        mock_create_chat_completion.side_effect = Exception("Uh-oh")

        resume = self.given_resume()
        job_description_text = "Job description lorem ispsum dolor."

        cover_letter_data = {
            "resume": resume_service.convert_resume_to_str(resume),
            "job_description": job_description_text,
        }
        messages = [
            openai.create_user_message(cover_letter_service.AI_CONTEXT_MESSAGE_BEGIN),
            openai.create_user_message(cover_letter_service.AI_CONTEXT_MESSAGE_TASK),
            openai.create_user_message(str(cover_letter_data)),
            openai.create_user_message(cover_letter_service.AI_CONTEXT_MESSAGE_RETURN),
        ]

        # When
        cover_letter = cover_letter_service.generate_cover_letter(
            resume, job_description_text
        )

        # Then
        self.assertEqual(CoverLetter.objects.count(), 0)
        self.assertEqual(cover_letter, False)
        mock_create_chat_completion.assert_called_once_with(
            messages=messages,
            model=cover_letter_service.GPT_MODEL_IN_USE,
            max_tokens=cover_letter_service.MAX_TOKENS_CHAT_COMPLETION,
        )

    @mock.patch("api.models.services.cover_letter_service.list_all_exp_variant_strings")
    @mock.patch("api.models.services.cover_letter_service.send_cover_letter_by_email")
    @mock.patch("api.models.services.cover_letter_service.generate_cover_letter")
    def test_generate_in_background_success(
        self, mock_generate, mock_send_email, mock_list_all_exp_variant_strings
    ):
        # Given
        customer = self.given_customer(
            email="email@gmail.com", num_cover_letters_generated=0
        )
        resume = self.given_resume(customer=customer)
        job_description_text = "Hey there"
        cover_letter = self.given_cover_letter(
            resume.id,
            job_description_text=job_description_text,
            generated_text="Generated text",
        )

        mock_generate.return_value = cover_letter
        mock_list_all_exp_variant_strings.return_value = ["_exp_2"]

        # When
        cover_letter_data = (
            cover_letter_service.generate_cover_letter_in_background_task(
                resume=resume,
                job_description_text=job_description_text,
                customer=customer,
                request_cookies=mock.ANY,
            )
        )

        # Then
        self.assertEqual(
            cover_letter_data,
            {
                "id": str(cover_letter.id),
                "job_description_text": job_description_text,
                "generated_text": "Generated text",
                "rating": None,
                "created_at": mock.ANY,
            },
        )
        mock_generate.assert_called_once_with(resume, job_description_text)

        mock_send_email.assert_called_once_with(
            customer=customer, cover_letter=cover_letter, resume=resume
        )

        customer_model = Customer.objects.get(user__email="email@gmail.com")
        self.assertEqual(customer_model.num_cover_letters_generated, 1)

        self.assertEqual(ConversionEvent.objects.count(), 1)
        conversion_event = ConversionEvent.objects.first()
        self.assertEqual(
            conversion_event.event_name,
            ConversionEventNameEnum.COVER_LETTER_GENERATE.value,
        )
        self.assertEqual(conversion_event.resume, resume)
        self.assertEqual(conversion_event.exp_variant_strings, ["_exp_2"])

    @mock.patch("api.models.services.cover_letter_service.send_cover_letter_by_email")
    @mock.patch("api.models.services.cover_letter_service.generate_cover_letter")
    def test_generate_in_background_error(self, mock_generate, mock_send_email):
        # Given
        customer = self.given_customer(
            email="email@gmail.com", num_cover_letters_generated=0
        )
        resume = self.given_resume(customer=customer)
        job_description_text = "Hey there"

        mock_generate.return_value = False

        # When
        background_task_error_data = (
            cover_letter_service.generate_cover_letter_in_background_task(
                resume=resume,
                job_description_text=job_description_text,
                customer=customer,
                request_cookies=mock.ANY,
            )
        )

        # Then
        self.assertEqual(
            background_task_error_data,
            {
                "is_error": True,
                "error_message": error_messages.COVER_LETTER_UNABLE_TO_GENERATE,
            },
        )
        mock_generate.assert_called_once_with(resume, job_description_text)

        mock_send_email.assert_not_called()

        customer_model = Customer.objects.get(user__email="email@gmail.com")
        self.assertEqual(customer_model.num_cover_letters_generated, 0)
        self.assertEqual(ConversionEvent.objects.count(), 0)

    @mock.patch("utils.integrations.sendgrid.sendgrid.sendgrid_mail_send.post")
    def test_send_cover_letter_by_email_success(self, mock_sendgrid_post):
        # Given
        customer = self.given_customer(
            email="email@gmail.com", num_cover_letters_generated=0
        )
        resume = self.given_resume(customer=customer)
        job_description_text = "We are looking for ..."
        generated_text = """
            Dear Hiring Manager,
            I'm perfect for this job!
        """
        cover_letter = self.given_cover_letter(
            resume.id,
            job_description_text=job_description_text,
            generated_text=generated_text,
        )
        cover_letter_html = "<br />            Dear Hiring Manager,<br />"
        cover_letter_html += "            I'm perfect for this job!<br />"
        cover_letter_html += "        "

        class MockSendGridResponse:
            status_code = 202

        mock_sendgrid_post.return_value = MockSendGridResponse()

        # When
        with self.settings(SENDGRID_EMAIL_SENDER="somesender@gmail.com"):
            response = cover_letter_service.send_cover_letter_by_email(
                customer=customer, cover_letter=cover_letter, resume=resume
            )

        # Then
        mock_sendgrid_post.assert_called_once_with(
            request_body={
                "personalizations": [
                    {
                        "to": [{"email": customer.user.email}],
                        "dynamic_template_data": {
                            "firstName": resume.get_contact_info_first_name(),
                            "generatedCoverLetter": cover_letter_html,
                        },
                    }
                ],
                "from": {"email": "somesender@gmail.com", "name": "Sender"},
                "template_id": cover_letter_service.SENDGRID_EMAIL_TEMPLATE_ID,
            }
        )
        self.assertEqual(response, True)
        self.mocks["honeybadger"]["notify"].assert_not_called()

    @mock.patch("utils.integrations.sendgrid.sendgrid.sendgrid_mail_send.post")
    def test_send_cover_letter_by_email_invalid_status_code(self, mock_sendgrid_post):
        # Given
        customer = self.given_customer(
            email="email@gmail.com", num_cover_letters_generated=0
        )
        resume = self.given_resume(customer=customer)
        job_description_text = "We are looking for ..."
        cover_letter = self.given_cover_letter(
            resume.id,
            job_description_text=job_description_text,
            generated_text="Generated text",
        )

        class MockSendGridResponse:
            status_code = 500

        mock_sendgrid_post.return_value = MockSendGridResponse()

        # When
        with self.settings(SENDGRID_EMAIL_SENDER="somesender@gmail.com"):
            response = cover_letter_service.send_cover_letter_by_email(
                customer=customer, cover_letter=cover_letter, resume=resume
            )

        # Then
        mock_sendgrid_post.assert_called_once_with(
            request_body={
                "personalizations": [
                    {
                        "to": [{"email": customer.user.email}],
                        "dynamic_template_data": {
                            "firstName": resume.get_contact_info_first_name(),
                            "generatedCoverLetter": "Generated text",
                        },
                    }
                ],
                "from": {"email": "somesender@gmail.com", "name": "Sender"},
                "template_id": cover_letter_service.SENDGRID_EMAIL_TEMPLATE_ID,
            }
        )
        self.assertEqual(response, False)
        self.mocks["honeybadger"]["notify"].assert_called_once_with(
            error_message=error_messages.COVER_LETTER_UNABLE_TO_SEND_EMAIL,
        )

    @mock.patch("utils.integrations.sendgrid.sendgrid.sendgrid_mail_send.post")
    def test_send_cover_letter_by_email_unknown_error(self, mock_sendgrid_post):
        # Given
        customer = self.given_customer(
            email="email@gmail.com", num_cover_letters_generated=0
        )
        resume = self.given_resume(customer=customer)
        job_description_text = "We are looking for ..."
        cover_letter = self.given_cover_letter(
            resume.id,
            job_description_text=job_description_text,
            generated_text="Generated text",
        )

        mock_sendgrid_post.side_effect = Exception("Uh-oh")

        # When
        with self.settings(SENDGRID_EMAIL_SENDER="somesender@gmail.com"):
            response = cover_letter_service.send_cover_letter_by_email(
                customer=customer, cover_letter=cover_letter, resume=resume
            )

        # Then
        mock_sendgrid_post.assert_called_once_with(
            request_body={
                "personalizations": [
                    {
                        "to": [{"email": customer.user.email}],
                        "dynamic_template_data": {
                            "firstName": resume.get_contact_info_first_name(),
                            "generatedCoverLetter": "Generated text",
                        },
                    }
                ],
                "from": {"email": "somesender@gmail.com", "name": "BeamJobs"},
                "template_id": cover_letter_service.SENDGRID_EMAIL_TEMPLATE_ID,
            }
        )
        self.assertEqual(response, False)
        self.mocks["honeybadger"]["notify"].assert_called_once_with(
            error_message=error_messages.COVER_LETTER_UNABLE_TO_SEND_EMAIL,
        )

    @mock.patch("utils.integrations.fixpoint.fixpoint.record_single_user_feedback")
    def test_send_user_feedback_to_fixpoint_thumbs_up_success(
        self, mock_record_single_user_feedback
    ):
        # Given
        customer = self.given_customer()
        resume = self.given_resume(customer=customer)
        cover_letter = self.given_cover_letter(
            resume_id=resume.id,
            job_description_text="We are looking for ...",
            generated_text="Generated text",
            rating=CoverLetterFeedbackChoicesEnum.THUMBS_UP.value,
            customer=resume.customer,
            fixpoint_input_log_response_name="863626fe-bd39-4835-b638-796222a7c7a9",
        )
        mock_record_single_user_feedback.return_value = None

        # When
        cover_letter_service.send_user_feedback_to_fixpoint(cover_letter=cover_letter)

        # Then
        mock_record_single_user_feedback.assert_called_once_with(
            like={
                "log_name": str(cover_letter.fixpoint_input_log_response_name),
                "thumbs_reaction": ThumbsReaction.THUMBS_UP,
                "user_id": str(cover_letter.customer.user.id),
            },
        )
        self.mocks["honeybadger"]["notify"].assert_not_called()

    @mock.patch("utils.integrations.fixpoint.fixpoint.record_single_user_feedback")
    def test_send_user_feedback_to_fixpoint_thumbs_down_success(
        self, mock_record_single_user_feedback
    ):
        # Given
        customer = self.given_customer()
        resume = self.given_resume(customer=customer)
        cover_letter = self.given_cover_letter(
            resume_id=resume.id,
            job_description_text="We are looking for ...",
            generated_text="Generated text",
            rating=CoverLetterFeedbackChoicesEnum.THUMBS_DOWN.value,
            customer=resume.customer,
            fixpoint_input_log_response_name="863626fe-bd39-4835-b638-796222a7c7a9",
        )
        mock_record_single_user_feedback.return_value = None

        # When
        cover_letter_service.send_user_feedback_to_fixpoint(cover_letter=cover_letter)

        # Then
        mock_record_single_user_feedback.assert_called_once_with(
            like={
                "log_name": str(cover_letter.fixpoint_input_log_response_name),
                "thumbs_reaction": ThumbsReaction.THUMBS_DOWN,
                "user_id": str(cover_letter.customer.user.id),
            },
        )
        self.mocks["honeybadger"]["notify"].assert_not_called()

    @mock.patch("utils.integrations.fixpoint.fixpoint.record_single_user_feedback")
    def test_send_user_feedback_to_fixpoint_unknown_error(
        self, mock_record_single_user_feedback
    ):
        # Given
        customer = self.given_customer()
        resume = self.given_resume(customer=customer)
        cover_letter = self.given_cover_letter(
            resume_id=resume.id,
            job_description_text="We are looking for ...",
            generated_text="Generated text",
            rating=CoverLetterFeedbackChoicesEnum.THUMBS_DOWN.value,
            customer=resume.customer,
            fixpoint_input_log_response_name="863626fe-bd39-4835-b638-796222a7c7a9",
        )
        mock_record_single_user_feedback.side_effect = Exception("Uh-oh")

        # When
        cover_letter_service.send_user_feedback_to_fixpoint(cover_letter=cover_letter)

        # Then
        mock_record_single_user_feedback.assert_called_once_with(
            like={
                "log_name": str(cover_letter.fixpoint_input_log_response_name),
                "thumbs_reaction": ThumbsReaction.THUMBS_DOWN,
                "user_id": str(cover_letter.customer.user.id),
            },
        )
        self.mocks["honeybadger"]["notify"].assert_called_once()
