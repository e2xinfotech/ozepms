<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Tax\Queries\TaxRuleQuery;
use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TaxesController extends Controller
{
    public function index(Request $request, TaxRuleQuery $query, FormOptions $options, PropertyContext $context): View
    {
        $property = $context->property();

        return Page::render('property/taxes/index', [
            'list' => $query->list($request),
            'filters' => $request->only(['q', 'status', 'apply_to', 'tab', 'sort', 'dir', 'selected']),
            'options' => $options->taxOptions(),
            'has_own_rules' => $query->hasOwnRules(),
            'templates_available' => TaxRule::query()->templatesFor((string) $property->country_iso2)->exists(),
            'country' => $property->country_iso2,
        ], __('nav.taxes'));
    }
}
