<?php

namespace App\Domain\Platform;

use App\Models\AuditLog;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\SystemErrorEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Figures for the Super Admin dashboard. Booking and revenue figures come from
 * modules delivered in later phases; until those tables exist they report zero.
 */
class PlatformStatsService
{
    public function summary(): array
    {
        $byStatus = Property::query()->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        return [
            'properties' => (int) $byStatus->sum(),
            'properties_active' => (int) ($byStatus['active'] ?? 0),
            'properties_inactive' => (int) (($byStatus['inactive'] ?? 0) + ($byStatus['suspended'] ?? 0)),
            'properties_onboarding' => (int) ($byStatus['onboarding'] ?? 0),
            'countries' => Property::query()->distinct()->count('country_iso2'),
            'rooms' => $this->countIfTable('physical_units'),
            'users' => User::query()->where('status', 'active')->count(),
            'bookings_month' => $this->countIfTable('reservations', fn ($q) => $q->where('created_at', '>=', now()->startOfMonth())),
            'revenue_month' => null,
        ];
    }

    public function distributionByType(): array
    {
        return Property::query()
            ->join('property_types', 'property_types.id', '=', 'properties.property_type_id')
            ->select('property_types.code', DB::raw('count(*) as total'))
            ->groupBy('property_types.code')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['type' => $row->code, 'label' => __('property_type.'.$row->code), 'total' => (int) $row->total])
            ->all();
    }

    public function recentActivity(int $limit = 6): array
    {
        return AuditLog::query()
            ->with(['user:id,name', 'property:id,name,code'])
            ->whereNotIn('action', ['auth.logout'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'label' => \App\Domain\Audit\Queries\AuditQuery::actionLabel($log->action),
                'user' => $log->user?->name,
                'property' => $log->property?->name,
                'at' => $log->created_at?->toIso8601String(),
            ])->all();
    }

    public function systemOverview(): array
    {
        return [
            'open_errors' => SystemErrorEvent::query()->whereNull('resolved_at')->count(),
            'errors_24h' => SystemErrorEvent::query()->where('last_seen_at', '>=', now()->subDay())->sum('occurrences'),
            'expiring_subscriptions' => Subscription::query()->whereIn('status', ['trial', 'active'])
                ->whereBetween('ends_on', [now()->toDateString(), now()->addDays(config('ozepms.subscription.expiry_warning_days'))->toDateString()])
                ->count(),
            'onboarding_properties' => Property::query()->where('status', 'onboarding')->count(),
            'connected_channels' => $this->countIfTable('channel_connections', fn ($q) => $q->where('status', 'active')),
        ];
    }

    /**
     * Bookings created per day across all properties (most recent $days days).
     *
     * @return array<int, array{date: string, bookings: int}>
     */
    public function bookingPerformance(int $days = 14): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $counts = Schema::hasTable('reservations')
            ? DB::table('reservations')->where('created_at', '>=', $from)
                ->select(DB::raw('date(created_at) as day'), DB::raw('count(*) as total'))
                ->groupBy('day')->pluck('total', 'day')
            : collect();

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $day, 'bookings' => (int) ($counts[$day] ?? 0)];
        }

        return $out;
    }

    /** First properties by name, with room counts, for the dashboard table. */
    public function propertiesOverview(int $limit = 8): array
    {
        return Property::query()
            ->with(['type:id,code,label_key', 'country:iso2,name'])
            ->select('properties.*')
            ->selectSub(DB::table('physical_units')->selectRaw('count(*)')
                ->whereColumn('physical_units.property_id', 'properties.id')
                ->whereNull('physical_units.deleted_at')->where('physical_units.is_active', true), 'rooms_count')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Property $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'location' => $p->locationLabel(),
                'country_code' => $p->country_iso2,
                'type_label' => $p->type ? __($p->type->label_key) : null,
                'rooms' => (int) $p->rooms_count,
                'status' => $p->status,
            ])->all();
    }

    private function countIfTable(string $table, ?callable $scope = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $query = DB::table($table);
        if ($scope) {
            $scope($query);
        }

        return $query->count();
    }
}
