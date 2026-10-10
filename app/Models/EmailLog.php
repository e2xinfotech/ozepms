<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** One e-mail the application sent or tried to send. property_id is empty for platform e-mails. */
class EmailLog extends Model
{
    use HasPublicId;

    protected $table = 'email_logs';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
