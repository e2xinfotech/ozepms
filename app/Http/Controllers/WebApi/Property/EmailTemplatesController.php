<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Audit\AuditLogger;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\ReservationMailer;
use App\Http\Controllers\Controller;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The hotel's own wording of the e-mails sent to its guests (default text, edit, reset, preview). */
class EmailTemplatesController extends Controller
{
    public function index(PropertyContext $context, MailSettings $settings, ReservationMailer $mailer): JsonResponse
    {
        $property = $context->property();
        $defaults = $mailer->defaults($property);
        $rows = [];
        foreach (MailSettings::EVENTS as $event) {
            $custom = $settings->template($property, $event);
            $rows[] = [
                'event' => $event, 'custom' => $custom !== null, 'subject' => $custom['subject'] ?? $defaults[$event]['subject'], 'body' => $custom['body'] ?? $defaults[$event]['body'],
                'default_subject' => $defaults[$event]['subject'], 'default_body' => $defaults[$event]['body'],
            ];
        }

        return response()->json(['data' => $rows, 'placeholders' => MailSettings::PLACEHOLDERS]);
    }

    public function update(Request $request, PropertyContext $context, MailSettings $settings, ReservationMailer $mailer, AuditLogger $audit, mixed $property, string $event): JsonResponse
    {
        $data = $this->validated($request, $event);
        $p = $context->property();
        $defaults = $mailer->defaults($p)[$event];
        // Saving the default text unchanged is the same as having no wording of its own.
        $same = trim($data['subject']) === $defaults['subject'] && trim($data['body']) === $defaults['body'];
        $settings->saveTemplate($p, $event, $same ? null : $data['subject'], $same ? null : $data['body']);
        $audit->log('mail.template_updated', $p, ['event' => $event, 'custom' => ! $same]);

        return response()->json(['message' => __('mailsettings.template_saved'), 'custom' => ! $same]);
    }

    public function reset(PropertyContext $context, MailSettings $settings, AuditLogger $audit, mixed $property, string $event): JsonResponse
    {
        abort_unless(in_array($event, MailSettings::EVENTS, true), 404);
        $settings->saveTemplate($context->property(), $event, null, null);
        $audit->log('mail.template_reset', $context->property(), ['event' => $event]);

        return response()->json(['message' => __('mailsettings.template_was_reset')]);
    }

    public function preview(Request $request, PropertyContext $context, ReservationMailer $mailer, mixed $property, string $event): JsonResponse
    {
        $data = $this->validated($request, $event);

        return response()->json($mailer->preview($context->property(), $event, $data['subject'], $data['body']));
    }

    /** @return array{subject: string, body: string} */
    private function validated(Request $request, string $event): array
    {
        abort_unless(in_array($event, MailSettings::EVENTS, true), 404);
        $v = $request->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:3000']]);

        return ['subject' => $v['subject'], 'body' => $v['body']];
    }
}
