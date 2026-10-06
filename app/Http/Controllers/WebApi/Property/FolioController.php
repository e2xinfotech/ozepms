<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\Queries\BillingPresenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsBilling;
use App\Http\Requests\Property\Billing\PostChargeRequest;
use App\Http\Requests\Property\Billing\VoidLineRequest;
use App\Models\Service;
use App\Models\TaxCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Folio of a reservation: lines, extras / adjustments / discounts, voids. */
class FolioController extends Controller
{
    use FindsBilling;

    public function __construct(
        private readonly FolioService $folios,
        private readonly BillingPresenter $presenter,
    ) {}

    public function show(string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);

        return response()->json($this->presenter->folio($r, $this->abilities()));
    }

    /** Services, tax categories and payment methods for the dialogs. */
    public function options(Request $request): JsonResponse
    {
        $id = (string) $request->query('reservation', '');
        $r = $id !== '' ? $this->reservationOr404($id) : null;

        return response()->json($this->presenter->options($r));
    }

    public function charge(PostChargeRequest $request, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $v = $request->validated();
        $service = null;
        if (! empty($v['service_id'])) {
            $service = Service::query()->where('public_id', $v['service_id'])->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['service_id' => __('billing.errors.service_not_found')]);
        }
        $data = [
            'type' => $v['type'], 'service' => $service, 'description' => $v['description'] ?? null,
            'quantity' => $v['quantity'] ?? null, 'unit_price' => $v['unit_price'] ?? null,
            'discount_percent' => $v['discount_percent'] ?? null, 'idempotency_key' => $v['idempotency_key'], 'note' => $v['note'] ?? null,
        ];
        if (array_key_exists('tax_category', $v)) {
            $data['tax_category_id'] = $v['tax_category'] ? (TaxCategory::query()->where('code', $v['tax_category'])->value('id')
                ?? throw ValidationException::withMessages(['tax_category' => __('billing.errors.tax_category')])) : null;
        }
        $line = $this->folios->postCharge($r, $data, $request->user());

        return response()->json(['line' => $line->public_id, 'folio' => $this->presenter->folio($r->fresh(), $this->abilities()), 'message' => __('billing.messages.charge_posted')], 201);
    }

    public function void(VoidLineRequest $request, string $reservation, string $line): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $model = $this->lineOr404($r, $line);
        $this->folios->voidLine($model, (string) $request->validated('reason'), $request->user(), $this->allowed('billing.override'));

        return response()->json(['folio' => $this->presenter->folio($r->fresh(), $this->abilities()), 'message' => __('billing.messages.line_voided')]);
    }

    /** @return array<string, bool> */
    private function abilities(): array
    {
        return [
            'post' => $this->allowed('folio.post'),
            'override' => $this->allowed('billing.override'),
            'invoice' => $this->allowed('invoices.manage'),
            'payments' => $this->allowed('payments.manage'),
        ];
    }
}
