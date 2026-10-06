<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Billing\Queries\ServiceQuery;
use App\Domain\Billing\ServiceCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsBilling;
use App\Http\Requests\Property\Billing\SaveServiceRequest;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Services & extras setup. */
class ServicesController extends Controller
{
    use FindsBilling;

    public function __construct(
        private readonly ServiceCatalogService $services,
        private readonly ServiceQuery $query,
        private readonly PropertyContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query->page($request));
    }

    public function show(string $service): JsonResponse
    {
        return response()->json(['service' => $this->query->detail($this->serviceOr404($service))]);
    }

    public function store(SaveServiceRequest $request): JsonResponse
    {
        $service = $this->services->create($this->context->property(), $request->validated());

        return response()->json(['service' => $this->query->detail($service), 'message' => __('billing.messages.service_saved')], 201);
    }

    public function update(SaveServiceRequest $request, string $service): JsonResponse
    {
        $model = $this->services->update($this->serviceOr404($service), $request->validated());

        return response()->json(['service' => $this->query->detail($model), 'message' => __('billing.messages.service_saved')]);
    }

    public function status(Request $request, string $service): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $model = $this->services->setActive($this->serviceOr404($service), (bool) $data['is_active']);

        return response()->json(['service' => $this->query->detail($model), 'message' => __('billing.messages.service_saved')]);
    }
}
