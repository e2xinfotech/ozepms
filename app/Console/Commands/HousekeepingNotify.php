<?php

namespace App\Console\Commands;

use App\Domain\Housekeeping\HousekeepingService;
use Illuminate\Console\Command;

/** E-mails the cleaning requests that were opened at check-out to the staff responsible for the rooms. */
class HousekeepingNotify extends Command
{
    protected $signature = 'housekeeping:notify';

    protected $description = 'Send cleaning e-mails to the housekeeping staff responsible for checked-out rooms';

    public function handle(HousekeepingService $housekeeping): int
    {
        $r = $housekeeping->notifyDue();
        $this->line("e-mails={$r['sent']} rooms={$r['rooms']} failed={$r['failed']}");

        return self::SUCCESS;
    }
}
