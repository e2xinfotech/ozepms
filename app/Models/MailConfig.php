<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** SMTP account and e-mail switches of one property; the row without a property is the platform default. */
class MailConfig extends Model
{
    protected $table = 'mail_configs';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'events' => 'array', 'templates' => 'array', 'send_for_channels' => 'boolean', 'on_behalf' => 'boolean', 'pre_arrival_days' => 'integer', 'port' => 'integer'];
    }
}
