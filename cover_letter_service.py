from typing import Dict, Union
from api.choices.cover_letter_feedback_choices import CoverLetterFeedbackChoicesEnum
from api.models import CoverLetter, Resume, Customer, ConversionEvent
from api.models.analytics_models.conversion_event import ConversionEventNameEnum
from api.models.services import resume_service
from api.serializers.cover_letter_serializer import CoverLetterSerializer
from api.views.services.ab_testing_cookie_service import list_all_exp_variant_strings
from django.conf import settings
from fixpoint_sdk import ThumbsReaction
from utils import error_messages
from utils.integrations.honeybadger.honeybadger import notify
from utils.integrations.fixpoint import fixpoint
from utils.integrations.openai import openai
from utils.integrations.redis_queue import BackgroundTaskErrorSerializer
from utils.integrations.sendgrid.sendgrid import sendgrid_mail_send

# The maximum number of tokens to generate in the chat completion.
MAX_TOKENS_CHAT_COMPLETION = 600

AI_CONTEXT_MESSAGE_BEGIN = """
    You are a Job applicant. You will be provided with your resume and a
    job description you are applying for.
"""

AI_CONTEXT_MESSAGE_TASK = """
    First take a moment to fully understand your career objective, 
    based on the provided resume. Then, create a cover letter strictly
    for the provided job description highlighting all your skills, experiences,
    projects and certifications relevant to the job. All the necessary
    information will be provided in the next message.
"""

AI_CONTEXT_MESSAGE_RETURN = """
    You should return around 5 paragraphs in about 300 words.
"""

GPT_MODEL_IN_USE = openai.GPTModels.GPT4_OMNI.value

# Note that very long conversations are more likely to receive incomplete
# replies. For example, a gpt-3.5-turbo conversation that is 4090 tokens long
# will have its reply cut off after just 6 tokens.
# https://platform.openai.com/docs/guides/gpt/managing-tokens
MAX_INPUT_TOKENS = openai.get_max_token_limit(GPT_MODEL_IN_USE) * 0.7

# The cover letter generator email template on Sendgrid.
# https://mc.sendgrid.com/dynamic-templates/d-ed675a4dc1684cbd81f792dcc7579d74
SENDGRID_EMAIL_TEMPLATE_ID = "abcde"


def generate_cover_letter(
    resume: Resume, job_description_text: str
) -> Union[CoverLetter, bool]:
    """Generates a cover letter for a given resume and job description."""

    resume_text = resume_service.convert_resume_to_str(resume)
    num_input_tokens = openai.num_tokens_from_string(resume_text + job_description_text)
    if num_input_tokens > MAX_INPUT_TOKENS:
        return False

    cover_letter_data = {
        "resume": resume_text,
        "job_description": job_description_text,
    }
    messages = [
        openai.create_user_message(AI_CONTEXT_MESSAGE_BEGIN),
        openai.create_user_message(AI_CONTEXT_MESSAGE_TASK),
        openai.create_user_message(str(cover_letter_data)),
        openai.create_user_message(AI_CONTEXT_MESSAGE_RETURN),
    ]

    try:
        (
            openai_response,
            fixpoint_input_log,
            fixpoint_output_log,
        ) = fixpoint.create_chat_completion(
            messages=messages,
            model=GPT_MODEL_IN_USE,
            max_tokens=MAX_TOKENS_CHAT_COMPLETION,
        )

        return CoverLetter.objects.create(
            resume_id=resume.id,
            customer=resume.customer,
            job_description_text=job_description_text,
            generated_text=openai_response.choices[0].message.content,
            fixpoint_input_log_response_name=fixpoint_input_log["name"],
        )
    except Exception as e:
        return False


def send_cover_letter_by_email(
    customer: Customer,
    cover_letter: CoverLetter,
    resume: Resume,
) -> bool:
    """Sends an email with the generated cover letter to customer using
    Sendgrid.
    """
    # To keep the line breaks and make the cover letter look better
    # when displayed in an email.
    cover_letter_html = cover_letter.generated_text.replace("\n", "<br />")

    dynamic_template_data = {
        "firstName": resume.get_contact_info_first_name(),
        "generatedCoverLetter": cover_letter_html,
    }
    to = [{"email": customer.user.email}]
    data = {
        "personalizations": [
            {
                "to": to,
                "dynamic_template_data": dynamic_template_data,
            }
        ],
        "from": {"email": settings.SENDGRID_EMAIL_SENDER, "name": "Jobs"},
        "template_id": SENDGRID_EMAIL_TEMPLATE_ID,
    }
    try:
        response = sendgrid_mail_send.post(request_body=data)
        if response.status_code > 299:
            notify(
                error_message=error_messages.COVER_LETTER_UNABLE_TO_SEND_EMAIL,
            )
            return False

        return True
    except Exception:
        notify(
            error_message=error_messages.COVER_LETTER_UNABLE_TO_SEND_EMAIL,
        )
        return False


def generate_cover_letter_in_background_task(
    resume: Resume, job_description_text: str, customer: Customer, request_cookies: Dict
):
    """Performs cover letter generation. Intended to be run in background task.

    Also performs necessary side effects, like incrementing a customer's
    num_cover_letters_generated.
    """
    cover_letter = generate_cover_letter(resume, job_description_text)

    if cover_letter is False:
        notify(
            error_message=error_messages.COVER_LETTER_UNABLE_TO_GENERATE,
        )

        return BackgroundTaskErrorSerializer(
            error_message=error_messages.COVER_LETTER_UNABLE_TO_GENERATE
        ).serialize()

    customer.num_cover_letters_generated += 1
    customer.save()

    ConversionEvent.objects.create(
        event_name=ConversionEventNameEnum.COVER_LETTER_GENERATE.value,
        resume=resume,
        exp_variant_strings=list_all_exp_variant_strings(request_cookies),
    )

    # Ignore errors, since this isn't critical.
    send_cover_letter_by_email(
        customer=customer, cover_letter=cover_letter, resume=resume
    )

    response_serializer = CoverLetterSerializer(cover_letter)
    return response_serializer.data


def send_user_feedback_to_fixpoint(cover_letter: CoverLetter) -> None:
    """Sends user feedback to Fixpoint to LLM improvement process."""

    thumbs_reaction = ThumbsReaction.THUMBS_UP
    if cover_letter.rating == CoverLetterFeedbackChoicesEnum.THUMBS_DOWN.value:
        thumbs_reaction = ThumbsReaction.THUMBS_DOWN

    message = fixpoint.create_user_feedback_like_message(
        thumbs_reaction=thumbs_reaction,
        input_log_response_name=str(cover_letter.fixpoint_input_log_response_name),
        user_id=str(cover_letter.customer.user.id),
    )

    try:
        fixpoint.record_single_user_feedback(like=message)
    except Exception as e:
        notify(
            exception=e,
            error_message=error_messages.COVER_LETTER_UNABLE_TO_SEND_FEEDBACK_TO_FIXPOINT,
        )
