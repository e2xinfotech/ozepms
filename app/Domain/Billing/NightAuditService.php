<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reservations\ReservationService;
use App\Models\NightAuditRun;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Night audit: closes a property's business day.
 *  1. posts the room charge of every in-house night up to that day;
 *  2. marks confirmed / pending arrivals of that day (or earlier) that never arrived as no-show
 *     (through ReservationService::noShow, which releases the rooms and posts the fee);
 *  3. moves the business date to the next day.
 * One night_audit_runs row per property and day makes it idempotent: a day that was audited is
 * never audited again, a failed run can be repeated.
 */
class NightAuditService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PropertyContext $context,
    ) {}

    /**
     * Audits every active property whose day is due: the local time is past its audit time
     * (property setting "night_audit_time", default config), or it is more than one day behind.
     *
     * @return array<string, list<array<string, mixed>>> results per property code
     */
    public function runDue(bool $force = false): array
    {
        $out = [];
        Property::query()->where('status', 'active')->orderBy('id')->each(function (Property $property) use (&$out, $force) {
            $results = $this->run($property, null, $force);
            if ($results !== []) {
                $out[(string) $property->code] = $results;
            }
        });

        return $out;
    }

    /**
     * Audits the open business days of one property that are due ($force: ignore the audit
     * time; today is never audited).
     *
     * @return list<array<string, mixed>>
     */
    public function run(Property $property, ?User $by = null, bool $force = false): array
    {
        $results = [];
        $max = (int) config('ozepms.billing.night_audit_max_days', 7);
        for ($i = 0; $i < $max; $i++) {
            $property = $property->fresh();
            $day = BusinessDate::open($property);
            $today = BusinessDate::localToday($property);
            if (! $day->lessThan($today)) {
                break;
            }
            $behind = $day->lessThan($today->subDay());
            if (! $force && ! $behind && ! $this->pastAuditTime($property)) {
                break;
            }
            $result = $this->audit($property, $day, $by);
            $results[] = $result;
            if ($result['status'] !== 'completed') {
                break;
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function audit(Property $property, CarbonImmutable $day, ?User $by = null): array
    {
        $date = $day->toDateString();
        $previous = $this->context->has() ? $this->context->property() : null;
        $this->context->set($property, null, true);

        try {
            $run = $this->claim($property, $date, $by);
            if ($run === null) {
                return ['date' => $date, 'status' => 'skipped'];
            }
            $stats = ['nights_posted' => 0, 'no_shows' => 0, 'errors' => []];

            try {
                // 1. In-house nights up to the audited day.
                $folios = app(FolioService::class);
                Reservation::query()->where('property_id', $property->id)->where('status', 'checked_in')
                    ->where('check_in', '<=', $date)->orderBy('id')
                    ->each(function (Reservation $r) use ($folios, $day, $by, &$stats) {
                        try {
                            $stats['nights_posted'] += $folios->postRoomNights($r, $day, $by);
                        } catch (Throwable $e) {
                            report($e);
                            $stats['errors'][] = $r->booking_ref.': '.mb_substr($e->getMessage(), 0, 120);
                        }
                    });

                // 2. Arrivals that never came.
                if (config('ozepms.billing.auto_no_show')) {
                    $reservations = app(ReservationService::class);
                    Reservation::query()->where('property_id', $property->id)->whereIn('status', ['pending', 'confirmed'])
                        ->where('check_in', '<=', $date)->orderBy('id')
                        ->each(function (Reservation $r) use ($reservations, $by, &$stats) {
                            try {
                                $reservations->noShow($r, $by);
                                $stats['no_shows']++;
                            } catch (ValidationException $e) {
                                $stats['errors'][] = $r->booking_ref.': '.collect($e->errors())->flatten()->first();
                            } catch (Throwable $e) {
                                report($e);
                                $stats['errors'][] = $r->booking_ref.': '.mb_substr($e->getMessage(), 0, 120);
                            }
                        });
                }

                // 3. Next business day (never moves backwards).
                $next = $day->addDay()->toDateString();
                DB::table('properties')->where('id', $property->id)
                    ->where(fn ($q) => $q->whereNull('business_date')->orWhere('business_date', '<', $next))
                    ->update(['business_date' => $next, 'updated_at' => now()]);

                $stats['errors'] = array_slice($stats['errors'], 0, 50);
                $run->forceFill(['status' => 'completed', 'stats' => $stats, 'finished_at' => now(), 'error' => null])->save();
                $this->audit->log('billing.night_audit', $run, ['after' => ['business_date' => $date] + $stats], $property->id, $by?->id);

                return ['date' => $date, 'status' => 'completed'] + $stats;
            } catch (Throwable $e) {
                report($e);
                $run->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();

                return ['date' => $date, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        } finally {
            $previous ? $this->context->set($previous, null, true) : $this->context->clear();
        }
    }

    /**
     * Takes the run row of the day atomically; null when the day is done or another run is busy
     * with it (a run stuck for 30 minutes, or a failed one, may be taken over).
     */
    private function claim(Property $property, string $date, ?User $by): ?NightAuditRun
    {
        $inserted = DB::table('night_audit_runs')->insertOrIgnore([
            'property_id' => $property->id, 'business_date' => $date, 'status' => 'running',
            'run_by' => $by?->id, 'started_at' => now(),
        ]);
        if ($inserted === 0) {
            $taken = DB::table('night_audit_runs')->where('property_id', $property->id)->where('business_date', $date)
                ->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($r) => $r->where('status', 'running')->where('started_at', '<', now()->subMinutes(30))))
                ->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'run_by' => $by?->id]);
            if ($taken === 0) {
                return null;
            }
        }

        return NightAuditRun::query()->where('property_id', $property->id)->where('business_date', $date)->firstOrFail();
    }

    private function pastAuditTime(Property $property): bool
    {
        $setting = DB::table('property_settings')->where('property_id', $property->id)->where('key', 'night_audit_time')->value('value');
        $time = $setting !== null ? (string) json_decode((string) $setting, true) : (string) config('ozepms.billing.night_audit_time', '02:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '02:00';
        }

        return now($property->timezone ?: config('app.timezone'))->format('H:i') >= $time;
    }
}
