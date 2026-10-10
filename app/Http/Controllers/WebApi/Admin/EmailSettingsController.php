<?php

namespace App\Http\Controllers\WebApi\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\ReservationMailer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\MailSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The platform's default SMTP account, used by every property that has not set its own, and for system e-mails. */
class EmailSettingsController extends Controller
{
    public function update(MailSettingsRequest $request, MailSettings $settings, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $before = $settings->view(null);
        $after = $settings->save(null, $data);
        $audit->log('mail.platform_updated', null, ['before' => $before, 'after' => $after, 'password_changed' => ! empty($data['password'])]);

        return response()->json(['message' => __('mailsettings.saved'), 'email' => $after]);
    }

    public function test(Request $request, ReservationMailer $mailer): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:190']]);
        $error = $mailer->test(null, $data['to']);

        return response()->json($error === null
            ? ['ok' => true, 'message' => __('mailsettings.test_sent', ['to' => $data['to']])]
            : ['ok' => false, 'message' => __('mailsettings.test_failed'), 'error' => $error]);
    }
}
