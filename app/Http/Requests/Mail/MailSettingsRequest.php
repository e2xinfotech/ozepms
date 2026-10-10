<?php

namespace App\Http\Requests\Mail;

use App\Domain\Mail\MailSettings;
use Illuminate\Foundation\Http\FormRequest;

/** SMTP account and e-mail switches. A hotel may not point its SMTP host at the server's own network. */
class MailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** True for the platform default (set by platform staff), false for a property. */
    protected function isPlatform(): bool
    {
        return $this->routeIs('webapi.admin.*');
    }

    public function rules(): array
    {
        return [
            'host' => ['bail', 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/', function ($attribute, $value, $fail) {
                if ($value && ! $this->isPlatform() && $this->isInternal((string) $value)) {
                    $fail(__('mailsettings.errors.host'));
                }
            }],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'in:'.implode(',', MailSettings::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:255'],
            'clear_password' => ['nullable', 'boolean'],
            'from_address' => ['nullable', 'email:rfc', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email:rfc', 'max:190'],
            'events' => ['nullable', 'array', 'max:10'],
            'events.*' => ['boolean'],
            'send_for_channels' => ['nullable', 'boolean'],
        ];
    }

    private function isInternal(string $host): bool
    {
        if (strcasecmp($host, 'localhost') === 0 || ! str_contains($host, '.')) {
            return true;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false; // does not resolve: the send itself will report it
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
