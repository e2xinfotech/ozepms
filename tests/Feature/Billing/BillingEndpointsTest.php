<?php

namespace Tests\Feature\Billing;

use App\Models\FolioLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;

/** Billing JSON endpoints and pages: success, validation, permission denied, other property → 404. */
class BillingEndpointsTest extends BillingTestCase
{
    private function reservation(int $in = 2, int $out = 4)
    {
        return $this->book([$this->room($this->dlxBar, $in, $out)]);
    }

    private function otherReservation()
    {
        $rt = $this->makeRoomType(['code' => 'OTH'], 1, $this->other);
        $product = $this->product($rt, $this->bar($this->other), ['default_price' => '3000.00']);

        return $this->book([$this->room($product, 2, 3)], [], $this->other);
    }

    public function test_folio_and_options(): void
    {
        $r = $this->reservation();
        $this->makeService();
        $this->actingAs($this->owner)->getJson($this->api("/reservations/{$r->public_id}/folio"))->assertOk()
            ->assertJsonPath('summary.total', '8400.00')
            ->assertJsonPath('summary.balance', '8400.00')
            ->assertJsonCount(2, 'pending')
            ->assertJsonPath('can.post', true)
            ->assertJsonPath('folio.status', 'open');
        $this->actingAs($this->owner)->getJson($this->api("/billing/options?reservation={$r->public_id}"))->assertOk()
            ->assertJsonPath('services.0.code', 'XBED')
            ->assertJsonPath('services.0.quantity', '2')
            ->assertJsonPath('online', false)
            ->assertJsonPath('stay.nights', 2);
    }

    public function test_post_charge_and_void(): void
    {
        $r = $this->reservation();
        $svc = $this->makeService();
        $res = $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/folio/charges"), [
            'type' => 'service', 'service_id' => $svc->public_id, 'quantity' => '2', 'idempotency_key' => 'c-1',
        ])->assertCreated()->assertJsonPath('folio.lines.0.description', 'Extra bed');
        // Same key again → same line.
        $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/folio/charges"), [
            'type' => 'service', 'service_id' => $svc->public_id, 'quantity' => '2', 'idempotency_key' => 'c-1',
        ])->assertCreated()->assertJsonPath('line', $res->json('line'));
        $this->assertSame(1, FolioLine::acrossProperties()->count());

        $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/folio/lines/{$res->json('line')}/void"), ['reason' => 'Wrong room'])
            ->assertOk()->assertJsonPath('folio.lines.0.void', true)->assertJsonPath('folio.lines.1.reversal', true);
    }

    public function test_charge_validation(): void
    {
        $r = $this->reservation();
        $url = $this->api("/reservations/{$r->public_id}/folio/charges");
        $this->actingAs($this->owner)->postJson($url, ['type' => 'bogus'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['type', 'idempotency_key']]]);
        $this->actingAs($this->owner)->postJson($url, ['type' => 'adjustment', 'unit_price' => '12,5', 'idempotency_key' => 'x'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['unit_price']]]);
        $this->actingAs($this->owner)->postJson($url, ['type' => 'discount', 'discount_percent' => '150', 'idempotency_key' => 'x'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['discount_percent']]]);
        $this->actingAs($this->owner)->postJson($url, ['type' => 'service', 'description' => 'x', 'unit_price' => '-5', 'idempotency_key' => 'x'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($url, ['type' => 'service', 'service_id' => str_repeat('A', 26), 'idempotency_key' => 'x'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['service_id']]]);
        $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/folio/lines/".str_repeat('A', 26).'/void'), ['reason' => 'abc'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/folio/lines/".str_repeat('A', 26).'/void'), [])->assertStatus(422);
    }

    public function test_payments_refund_and_listing(): void
    {
        $r = $this->reservation();
        $url = $this->api("/reservations/{$r->public_id}/payments");
        $res = $this->actingAs($this->owner)->postJson($url, ['method' => 'card', 'amount' => '5000', 'reference' => '4242', 'idempotency_key' => 'pay-1'])
            ->assertCreated()->assertJsonPath('replayed', false)->assertJsonPath('summary.paid', '5000.00')->assertJsonPath('rows.0.method', 'card');
        $this->actingAs($this->owner)->postJson($url, ['method' => 'card', 'amount' => '5000', 'reference' => '4242', 'idempotency_key' => 'pay-1'])
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('id', $res->json('id'));
        $this->assertSame(1, Payment::acrossProperties()->count());

        $this->actingAs($this->owner)->postJson($url.'/'.$res->json('id').'/refund', ['amount' => '6000', 'notes' => 'too much', 'idempotency_key' => 'r-1'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['amount']]]);
        $this->actingAs($this->owner)->postJson($url.'/'.$res->json('id').'/refund', ['amount' => '1000', 'notes' => 'Discount agreed', 'method' => 'cash', 'idempotency_key' => 'r-1'])
            ->assertCreated()->assertJsonPath('summary.paid', '4000.00');
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.refundable', '4000.00');
        $this->actingAs($this->owner)->postJson($url, ['method' => 'cheque', 'amount' => '-1'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['method', 'amount', 'idempotency_key']]]);
        $this->actingAs($this->owner)->postJson($url.'/online', ['amount' => '100', 'idempotency_key' => 'o'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($url.'/'.$res->json('id').'/verify', ['razorpay_order_id' => 'order_x', 'razorpay_payment_id' => 'pay_x', 'razorpay_signature' => 'x'])->assertStatus(422);
    }

    public function test_invoices_issue_list_cancel_and_print_pages(): void
    {
        $r = $this->reservation(0, 2);
        $this->within(fn () => $this->folios()->postRoomNights($r, null, $this->owner));
        $res = $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/invoices"), ['bill_to_name' => 'Acme Pvt Ltd', 'bill_to_tax_no' => '27AAACA1234A1Z5'])
            ->assertCreated()->assertJsonPath('invoice.bill_to', 'Acme Pvt Ltd')->assertJsonPath('rows.0.total', '8400.00')->assertJsonPath('billable', false);
        $id = $res->json('invoice.id');
        $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/invoices"), [])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($this->api("/reservations/{$r->public_id}/invoices"), ['bill_to_tax_no' => 'bad gstin!'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['bill_to_tax_no']]]);
        $this->actingAs($this->owner)->getJson($this->api("/reservations/{$r->public_id}/invoices"))->assertOk()->assertJsonCount(1, 'rows');

        $this->actingAs($this->owner)->get($this->page('/invoices/'.$id))->assertOk()->assertSee($this->pageName('property/billing/invoice'), false);
        $this->actingAs($this->owner)->get($this->page("/reservations/{$r->public_id}/folio"))->assertOk()->assertSee($this->pageName('property/billing/folio'), false);

        $this->actingAs($this->owner)->postJson($this->api("/invoices/{$id}/cancel"), [])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($this->api("/invoices/{$id}/cancel"), ['reason' => 'Wrong GSTIN'])->assertOk()
            ->assertJsonPath('credit_note.type', 'credit_note')->assertJsonCount(2, 'rows')->assertJsonPath('billable', true);
        $this->assertSame(2, Invoice::acrossProperties()->count());
    }

    public function test_services_setup(): void
    {
        $this->actingAs($this->owner)->get($this->page('/services'))->assertOk()->assertSee($this->pageName('property/services/index'), false);
        $res = $this->actingAs($this->owner)->postJson($this->api('/services'), [
            'code' => 'apt', 'name' => 'Airport pickup', 'price' => '1200', 'tax_category' => 'service', 'posting_rule' => 'once',
        ])->assertCreated()->assertJsonPath('service.code', 'APT');
        $id = $res->json('service.id');
        $this->actingAs($this->owner)->postJson($this->api('/services'), [
            'code' => 'APT', 'name' => 'Dup', 'price' => '1', 'tax_category' => 'service', 'posting_rule' => 'once',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->actingAs($this->owner)->postJson($this->api('/services'), ['code' => 'a b', 'price' => 'x', 'tax_category' => 'nope', 'posting_rule' => 'weekly'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code', 'name', 'price', 'tax_category', 'posting_rule']]]);
        $this->actingAs($this->owner)->putJson($this->api('/services/'.$id), [
            'code' => 'APT', 'name' => 'Airport transfer', 'price' => '1500.00', 'tax_category' => 'service', 'posting_rule' => 'once', 'description' => 'One way',
        ])->assertOk()->assertJsonPath('service.name', 'Airport transfer')->assertJsonPath('service.price', '1500.00');
        $this->actingAs($this->owner)->postJson($this->api('/services/'.$id.'/status'), ['is_active' => false])->assertOk()->assertJsonPath('service.is_active', false);
        $this->actingAs($this->owner)->getJson($this->api('/services?status=inactive'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('counts.inactive', 1);
        $this->actingAs($this->owner)->getJson($this->api('/services/'.$id))->assertOk()->assertJsonPath('service.times_posted', 0);
    }

    public function test_permissions(): void
    {
        $r = $this->reservation();
        $svc = $this->makeService();
        $housekeeping = $this->member('housekeeping');
        $this->actingAs($housekeeping)->getJson($this->api("/reservations/{$r->public_id}/folio"))->assertForbidden();
        $this->actingAs($housekeeping)->get($this->page('/services'))->assertForbidden();

        // Reservations role: no billing at all; front desk: post and take payments, no invoices, no services setup.
        $res = $this->member('reservations');
        $this->actingAs($res)->getJson($this->api("/reservations/{$r->public_id}/payments"))->assertForbidden();
        $fd = $this->member('front_desk');
        $this->actingAs($fd)->getJson($this->api("/reservations/{$r->public_id}/folio"))->assertOk()->assertJsonPath('can.override', false)->assertJsonPath('can.invoice', false);
        $this->actingAs($fd)->postJson($this->api("/reservations/{$r->public_id}/payments"), ['method' => 'cash', 'amount' => '10', 'idempotency_key' => 'fd'])->assertCreated();
        $this->actingAs($fd)->postJson($this->api("/reservations/{$r->public_id}/invoices"), [])->assertForbidden();
        $this->actingAs($fd)->postJson($this->api('/services'), [])->assertForbidden();
        $this->actingAs($fd)->getJson($this->api('/services/'.$svc->public_id))->assertForbidden();

        // A cancellation fee can be waived only with billing.override (accounts yes, front desk no).
        $fee = $this->within(fn () => $this->folios()->postFee($r, '500.00', 'cancellation', $this->owner));
        $this->actingAs($fd)->postJson($this->api("/reservations/{$r->public_id}/folio/lines/{$fee->public_id}/void"), ['reason' => 'Goodwill'])->assertStatus(422);
        $this->actingAs($this->member('accounts'))->postJson($this->api("/reservations/{$r->public_id}/folio/lines/{$fee->public_id}/void"), ['reason' => 'Goodwill'])->assertOk();
        $this->actingAs($this->owner)->get($this->page('/services'))->assertOk();
        $this->assertNotNull(Service::acrossProperties()->first());
    }

    public function test_other_property_records_are_not_found(): void
    {
        $o = $this->otherReservation();
        $svc = $this->makeService(['code' => 'OTHERSVC'], $this->other);
        $this->within(fn () => $this->folios()->postRoomNights($o, null, $this->otherOwner), $this->other);
        $line = $this->lines($o)->first();
        $pay = $this->within(fn () => $this->payments()->record($o, ['method' => 'cash', 'amount' => '100', 'idempotency_key' => 'o'], $this->otherOwner), $this->other);
        $inv = $this->within(fn () => $this->invoices()->issue($this->folios()->open($o), [], $this->otherOwner), $this->other);

        $this->actingAs($this->owner);
        $this->getJson($this->api("/reservations/{$o->public_id}/folio"))->assertNotFound();
        $this->getJson($this->api("/reservations/{$o->public_id}/payments"))->assertNotFound();
        $this->getJson($this->api("/reservations/{$o->public_id}/invoices"))->assertNotFound();
        $this->getJson($this->api("/billing/options?reservation={$o->public_id}"))->assertNotFound();
        $this->postJson($this->api("/reservations/{$o->public_id}/folio/charges"), ['type' => 'adjustment', 'unit_price' => '1', 'description' => 'x', 'idempotency_key' => 'z'])->assertNotFound();
        $this->postJson($this->api("/reservations/{$o->public_id}/payments"), ['method' => 'cash', 'amount' => '1', 'idempotency_key' => 'z'])->assertNotFound();
        $this->postJson($this->api("/reservations/{$o->public_id}/invoices"), [])->assertNotFound();
        $this->postJson($this->api("/invoices/{$inv->public_id}/cancel"), ['reason' => 'abc'])->assertNotFound();
        $this->getJson($this->api('/services/'.$svc->public_id))->assertNotFound();
        $this->putJson($this->api('/services/'.$svc->public_id), ['code' => 'X', 'name' => 'X', 'price' => '1', 'tax_category' => 'service', 'posting_rule' => 'once'])->assertNotFound();
        $this->get($this->page('/invoices/'.$inv->public_id))->assertNotFound();
        $this->get($this->page("/reservations/{$o->public_id}/folio"))->assertNotFound();

        // Own reservation, other property's line / payment ids → 404.
        $r = $this->reservation();
        $this->postJson($this->api("/reservations/{$r->public_id}/folio/lines/{$line->public_id}/void"), ['reason' => 'abc'])->assertNotFound();
        $this->postJson($this->api("/reservations/{$r->public_id}/payments/{$pay->public_id}/refund"), ['amount' => '1', 'notes' => 'abc', 'idempotency_key' => 'q'])->assertNotFound();
        $this->postJson($this->api("/reservations/{$r->public_id}/folio/charges"), ['type' => 'service', 'service_id' => $svc->public_id, 'idempotency_key' => 'q'])->assertStatus(422);
    }
}
