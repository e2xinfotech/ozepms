<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Audit\AuditLogger;
use App\Domain\Mail\EmailService;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\ReservationMailer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\MailSettingsRequest;
use App\Models\EmailLog;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The hotel's e-mail: its own SMTP account, which e-mails go to guests, a test button and the log of what was sent. */
class EmailSettingsController extends Controller
{
    public function update(MailSettingsRequest $request, PropertyContext $context, MailSettings $settings, AuditLogger $audit): JsonResponse
    {
        $property = $context->property();
        $data = $request->validated();
        $before = $settings->view($property);
        $after = $settings->save($property, $data);
        $audit->log('mail.updated', $property, ['before' => $before, 'after' => $after, 'password_changed' => ! empty($data['password'])]);

        return response()->json(['message' => __('mailsettings.saved'), 'email' => $after]);
    }

    public function test(Request $request, PropertyContext $context, ReservationMailer $mailer): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:190']]);
        $error = $mailer->test($context->property(), $data['to']);

        return response()->json($error === null
            ? ['ok' => true, 'message' => __('mailsettings.test_sent', ['to' => $data['to']])]
            : ['ok' => false, 'message' => __('mailsettings.test_failed'), 'error' => $error]);
    }

    public function logs(PropertyContext $context): JsonResponse
    {
        $rows = EmailLog::query()->where('property_id', $context->id())->orderByDesc('id')->limit(30)
            ->get(['public_id', 'event', 'to_email', 'subject', 'status', 'error', 'sent_at', 'created_at']);

        return response()->json(['data' => $rows->map(fn ($l) => [
            'id' => $l->public_id, 'event' => $l->event, 'to' => $l->to_email, 'subject' => $l->subject, 'status' => $l->status,
            'error' => $l->error, 'at' => ($l->sent_at ?? $l->created_at)?->toIso8601String(),
        ])->all()]);
    }

    public function resend(PropertyContext $context, EmailService $mail, mixed $property, string $log): JsonResponse
    {
        $row = EmailLog::query()->where('property_id', $context->id())->where('public_id', $log)->where('body_html', '!=', '')->firstOrFail();
        $row = $mail->resend($row);

        return response()->json(['message' => $row->status === 'sent' ? __('mailsettings.sent') : __('mailsettings.queued'), 'status' => $row->status, 'error' => $row->error]);
    }
}
