import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Checkbox, Input, Modal, Select, Textarea, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { BillingProps } from '../BillingSlot';
import { billingApi, fmt, isPositive, loadOptions, newKey, submit, type BillingOptions, type Summary } from './shared';

interface Checkout { key: string; order_id: string; amount: number; currency: string; name: string; description: string; prefill: Record<string, string>; notes: Record<string, string>; payment_id: string }
interface RazorpayResult { razorpay_payment_id: string; razorpay_order_id: string; razorpay_signature: string }
type RazorpayCtor = new (o: Record<string, unknown>) => { open: () => void; on: (ev: string, cb: (r: { error?: { description?: string } }) => void) => void };

const ONLINE = 'online';

function loadCheckoutScript(): Promise<RazorpayCtor> {
    const w = window as unknown as { Razorpay?: RazorpayCtor };
    if (w.Razorpay) return Promise.resolve(w.Razorpay);
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.async = true;
        s.onload = () => (w.Razorpay ? resolve(w.Razorpay) : reject(new Error('checkout')));
        s.onerror = () => reject(new Error('checkout'));
        document.head.appendChild(s);
    });
}

/** Records a payment at the desk (cash, card, UPI, bank transfer, other) or takes it online. */
export default function AddPaymentModal({ reservation, open = true, onClose, onSaved, onChanged, amount }: BillingProps) {
    const [options, setOptions] = useState<BillingOptions | null>(null);
    const [summary, setSummary] = useState<Summary | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [form, setForm] = useState({ method: 'cash', amount: '', reference: '', notes: '', received_at: '', is_deposit: false });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const key = useRef(newKey());
    const cur = reservation.currency;

    const load = () => {
        setFailed(null);
        Promise.all([loadOptions(reservation.id), http.get<{ summary: Summary }>(billingApi(reservation.id, '/payments'))])
            .then(([o, p]) => {
                setOptions(o);
                setSummary(p.summary);
                setForm((f) => ({ ...f, amount: f.amount || (amount && isPositive(amount) ? amount : (isPositive(p.summary.balance) ? p.summary.balance : '')), is_deposit: ['inquiry', 'hold', 'pending', 'confirmed'].includes(reservation.status) }));
            })
            .catch((e: ApiError) => setFailed(e.message));
    };
    useEffect(load, [reservation.id]);

    const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }));
    const done = () => { onChanged?.(); onSaved?.(); };

    const payOnline = async () => {
        const res = await submit(() => http.post<{ checkout: Checkout; message?: string }>(billingApi(reservation.id, '/payments/online'), { amount: form.amount, idempotency_key: key.current }), setError);
        if (!res) return;
        let Razorpay: RazorpayCtor;
        try {
            Razorpay = await loadCheckoutScript();
        } catch {
            toast.error(t('billing.payment.checkout_unavailable'));
            return;
        }
        const c = res.checkout;
        await new Promise<void>((resolve) => {
            const rzp = new Razorpay({
                key: c.key, order_id: c.order_id, amount: c.amount, currency: c.currency, name: c.name, description: c.description,
                prefill: c.prefill, notes: c.notes,
                handler: async (r: RazorpayResult) => {
                    const ok = await submit(() => http.post<{ message: string }>(billingApi(reservation.id, `/payments/${c.payment_id}/verify`), r), setError);
                    if (ok) done();
                    resolve();
                },
                modal: { ondismiss: () => resolve() },
            });
            rzp.on('payment.failed', (r) => { toast.error(r.error?.description ?? t('billing.payment.online_failed')); });
            rzp.open();
        });
        // The next attempt is a new payment.
        key.current = newKey();
    };

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            if (form.method === ONLINE) {
                await payOnline();
                return;
            }
            const res = await submit(() => http.post<{ message: string }>(billingApi(reservation.id, '/payments'), {
                method: form.method, amount: form.amount, reference: form.reference || null, notes: form.notes || null,
                received_at: form.received_at || null, is_deposit: form.is_deposit, idempotency_key: key.current,
            }), setError);
            if (res) done();
        } finally {
            setBusy(false);
        }
    };
    const close = () => onClose?.();
    const err = (k: string) => error?.field(k);
    const methods = [...(options?.methods ?? []).map((m) => ({ value: m, label: t(`billing.methods.${m}`) })), ...(options?.online ? [{ value: ONLINE, label: t('billing.methods.online') }] : [])];

    return (
        <Modal open={open} title={t('billing.payment.title')} onClose={close} footer={<>
            <Button onClick={close}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon={form.method === ONLINE ? 'credit-card' : 'wallet'} loading={busy} disabled={!options} onClick={save}>
                {form.method === ONLINE ? t('billing.payment.pay_online') : t('billing.payment.record')}
            </Button>
        </>}>
            {failed ? <Alert tone="danger"><div className="row-between"><span>{failed}</span><Button size="sm" onClick={load}>{t('billing.retry')}</Button></div></Alert>
                : !options || !summary ? <div className="skeleton" style={{ height: 220 }} />
                    : <form className="stack" onSubmit={(e) => { e.preventDefault(); save(); }}>
                        <div className="billing-preview row-between">
                            <span>{t('billing.summary.balance')}</span>
                            <strong className="num">{fmt(summary.balance, cur)}</strong>
                        </div>
                        <div className="form-grid">
                            <Select fieldClass="span-6" label={t('billing.fields.method')} required value={form.method} options={methods} autoFocus
                                onChange={(e) => set('method', e.target.value)} error={err('method')} />
                            <Input fieldClass="span-6" type="number" min={0.01} step="0.01" label={t('billing.fields.amount')} required suffix={cur} className="num"
                                value={form.amount} onChange={(e) => set('amount', e.target.value)} error={err('amount')} />
                            {form.method !== ONLINE && <>
                                <Input fieldClass="span-6" label={t('billing.fields.reference')} optional maxLength={100} value={form.reference}
                                    placeholder={t(`billing.payment.reference_${['card', 'upi', 'bank_transfer'].includes(form.method) ? form.method : 'other'}`)}
                                    onChange={(e) => set('reference', e.target.value)} error={err('reference')} />
                                <Input fieldClass="span-6" type="date" label={t('billing.fields.received_at')} optional value={form.received_at}
                                    onChange={(e) => set('received_at', e.target.value)} error={err('received_at')} hint={t('billing.payment.received_hint')} />
                                <div className="field span-12"><Checkbox checked={form.is_deposit} onChange={(e) => set('is_deposit', e.target.checked)} label={t('billing.payment.deposit')} /></div>
                                <Textarea fieldClass="span-12" label={t('billing.fields.note')} optional rows={2} maxLength={255} value={form.notes} onChange={(e) => set('notes', e.target.value)} />
                            </>}
                            {form.method === ONLINE && <div className="span-12"><Alert tone="info">{t('billing.payment.online_hint')}</Alert></div>}
                            {err('idempotency_key') && <div className="span-12"><Alert tone="danger">{err('idempotency_key')}</Alert></div>}
                        </div>
                        <button type="submit" hidden />
                    </form>}
        </Modal>
    );
}
