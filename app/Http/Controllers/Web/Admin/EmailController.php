<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Mail\MailSettings;
use App\Http\Controllers\Controller;
use App\Support\Page;
use Illuminate\Contracts\View\View;

/** The platform's default SMTP account (used by hotels without their own, and for system e-mails). */
class EmailController extends Controller
{
    public function index(MailSettings $mail): View
    {
        return Page::render('admin/email/index', ['email' => $mail->view(null), 'can_update' => true], __('mailsettings.platform_title'));
    }
}
