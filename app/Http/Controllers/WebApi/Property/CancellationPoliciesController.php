<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Rates\CancellationPolicyService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\SaveCancellationPolicyRequest;
use Illuminate\Http\JsonResponse;

/** Cancellation policies, created and edited from the rate plan form. */
class CancellationPoliciesController extends Controller
{
    use FindsAccommodation;

    public function __construct(private readonly CancellationPolicyService $policies) {}

    public function store(SaveCancellationPolicyRequest $request, FormOptions $options): JsonResponse
    {
        $policy = $this->policies->create($request->validated());

        return response()->json([
            'message' => __('rates.messages.policy_saved'),
            'policy' => $policy->code,
            'policies' => $options->cancellationPolicies(),
        ], 201);
    }

    public function update(SaveCancellationPolicyRequest $request, FormOptions $options, mixed $property, string $policy): JsonResponse
    {
        $model = $this->policies->update($this->policyOr404($policy), $request->validated());

        return response()->json([
            'message' => __('rates.messages.policy_saved'),
            'policy' => $model->code,
            'policies' => $options->cancellationPolicies(),
        ]);
    }
}
