<?php

namespace App\Console\Commands;

use App\Domain\BookingEngine\BookingEngineService;
use Illuminate\Console\Command;

/** Online bookings waiting for payment: confirmed when paid meanwhile, otherwise released after their hold. */
class BookingExpireHolds extends Command
{
    protected $signature = 'booking:expire-holds';

    protected $description = 'Release booking-engine rooms whose online payment did not arrive in time';

    public function handle(BookingEngineService $engine): int
    {
        [$confirmed, $cancelled] = $engine->expireHolds();
        $this->line("confirmed={$confirmed} released={$cancelled}");

        return self::SUCCESS;
    }
}
