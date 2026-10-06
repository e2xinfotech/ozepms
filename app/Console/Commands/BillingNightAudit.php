<?php

namespace App\Console\Commands;

use App\Domain\Billing\NightAuditService;
use App\Models\Property;
use Illuminate\Console\Command;

/**
 * Closes business days (scheduled every 15 minutes; each property is audited after its own
 * audit time in its timezone). --property=P1001 audits one property, --force ignores the audit
 * time (today is never closed). Safe to run again: a closed day is skipped.
 */
class BillingNightAudit extends Command
{
    protected $signature = 'billing:night-audit {--property= : Property code} {--force : Run now, even before the audit time}';

    protected $description = 'Night audit: post in-house room charges, mark no-shows and move the business date';

    public function handle(NightAuditService $audits): int
    {
        if ($code = $this->option('property')) {
            $property = Property::query()->where('code', $code)->first();
            if ($property === null) {
                $this->error('Unknown property '.$code);

                return self::FAILURE;
            }
            $results = [(string) $property->code => $audits->run($property, null, (bool) $this->option('force'))];
        } else {
            $results = $audits->runDue();
        }

        $failed = false;
        foreach ($results as $code => $days) {
            foreach ($days as $day) {
                $failed = $failed || $day['status'] === 'failed';
                $this->line(sprintf('%s %s %s nights=%d no_shows=%d errors=%d', $code, $day['date'], $day['status'],
                    $day['nights_posted'] ?? 0, $day['no_shows'] ?? 0, count($day['errors'] ?? [])));
            }
        }
        if ($results === []) {
            $this->line('Nothing due.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
