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
 * Figures for the Super Admin dashboard (properties, rooms, users, bookings, room revenue,
 * subscriptions). Counts of modules whose tables do not exist report zero.
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
            'rooms' => $this->countIfTable('physical_units', fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)),
            'users' => User::query()->where('status', 'active')->count(),
            'bookings_month' => $this->countIfTable('reservations', fn ($q) => $q->where('created_at', '>=', now()->startOfMonth())),
            'revenue_month' => $this->roomRevenueThisMonth(),
        ];
    }

    /**
     * Room revenue of all properties for stay nights in the current month (net of discounts,
     * before tax), one total per currency because properties bill in their own currency.
     *
     * @return list<array{currency: string, amount: string}>
     */
    private function roomRevenueThisMonth(): array
    {
        if (! Schema::hasTable('reservation_room_nights')) {
            return [];
        }

        return DB::table('reservation_room_nights as n')
            ->join('properties as p', 'p.id', '=', 'n.property_id')
            ->where('n.is_active', true)
            ->whereBetween('n.stay_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->groupBy('p.currency_code')->orderBy('p.currency_code')
            ->selectRaw('p.currency_code as currency, SUM(n.net_price) as amount')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'amount' => number_format((float) $r->amount, 2, '.', '')])
            ->all();
    }

    /**
     * Subscription and inventory figures of the whole platform (spec: trial, suspended and
     * expired properties, active and expiring subscriptions, subscription revenue, room types,
     * PMS rooms and reservations).
     */
    public function platform(): array
    {
        $today = now()->toDateString();
        // Current subscription of each property = latest non-cancelled one.
        $current = Subscription::query()->whereNot('status', 'cancelled')
            ->whereIn('id', Subscription::query()->whereNot('status', 'cancelled')
                ->selectRaw('max(id)')->groupBy('property_id'))
            ->with('plan:id,grace_days')
            ->get(['id', 'property_id', 'plan_id', 'status', 'ends_on', 'grace_ends_on', 'price', 'currency_code']);

        $status = fn (Subscription $s) => app(\App\Domain\Subscription\SubscriptionService::class)->effectiveStatus($s);
        $byStatus = $current->countBy($status);

        // Revenue of the paid subscriptions running today, per currency.
        $revenue = $current->filter(fn (Subscription $s) => $status($s) === 'active' && $s->ends_on->toDateString() >= $today)
            ->groupBy('currency_code')
            ->map(fn ($group, $currency) => ['currency' => $currency, 'amount' => $group->reduce(fn ($sum, $s) => bcadd($sum, (string) $s->price, 2), '0.00')])
            ->values()->all();

        return [
            'trial' => (int) ($byStatus['trial'] ?? 0),
            'active_subscriptions' => (int) ($byStatus['active'] ?? 0),
            'grace' => (int) ($byStatus['grace'] ?? 0),
            'expired' => (int) ($byStatus['expired'] ?? 0),
            'suspended_properties' => Property::query()->where('status', 'suspended')->count(),
            'subscription_revenue' => $revenue,
            'room_types' => $this->countIfTable('room_types', fn ($q) => $q->whereNull('deleted_at')),
            'units' => $this->countIfTable('physical_units', fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)),
            'reservations' => $this->countIfTable('reservations'),
        ];
    }

    /** Latest registered properties. */
    public function recentRegistrations(int $limit = 5): array
    {
        return Property::query()->with('country:iso2,name')->latest('id')->limit($limit)->get()
            ->map(fn (Property $p) => [
                'code' => $p->code, 'name' => $p->name, 'location' => $p->locationLabel(),
                'country_code' => $p->country_iso2, 'status' => $p->status, 'at' => $p->created_at?->toIso8601String(),
            ])->all();
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
            ->when(($hidden = app(\App\Domain\Users\PlatformHierarchy::class)->hiddenUserIds(request()->user())) !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $hidden))
                ->where(fn ($w) => $w->whereNull('impersonator_id')->orWhereNotIn('impersonator_id', $hidden))
                ->whereNotIn('action', ['user.super_admin_created_console']))
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
