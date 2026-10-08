<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Subscription\PlanService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\SubscriptionPlan;
use App\Support\Listing;
use App\Support\Lookups;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlansController extends Controller
{
    /** Subscription plans with the number of properties currently on each. */
    public function index(Request $request, \App\Domain\Access\AccessService $access): View
    {
        $filters = Listing::filters($request, ['status']);

        $query = SubscriptionPlan::query()
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->select('subscription_plans.*')
            ->selectSub(DB::table('subscriptions')->selectRaw('count(distinct property_id)')
                ->whereColumn('subscriptions.plan_id', 'subscription_plans.id')
                ->whereIn('subscriptions.status', ['trial', 'active', 'grace']), 'properties_count')
            ->orderBy('sort_order');

        $all = SubscriptionPlan::query()->count();
        $active = SubscriptionPlan::query()->where('is_active', true)->count();

        return Page::render('admin/plans/index', Listing::paginate($query, $request, fn (SubscriptionPlan $p) => (new PlanResource($p))->resolve($request)) + [
            'counts' => ['all' => $all, 'active' => $active, 'inactive' => $all - $active],
            'filters' => $filters,
            'features' => PlanService::FEATURES,
            'currencies' => Lookups::currencies(),
            'can_approve' => $access->allows($request->user(), 'platform.plans.approve'),
        ], __('subscription.plans'));
    }
}
