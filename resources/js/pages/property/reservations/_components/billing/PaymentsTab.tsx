import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Badge, Button, Card, EmptyState, Input, Modal, RowMenu, Select, Textarea } from '@/components/ui';
import { date, dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { BillingProps } from '../BillingSlot';
import AddPaymentModal from './AddPaymentModal';
import { billingApi, fmt, isPositive, newKey, submit, type PaymentRow, type PaymentsData } from './shared';

function methodLabel(p: PaymentRow): string {
    return p.method === 'gateway' ? t('billing.methods.online') : t(`billing.methods.${p.method}`);
}

function StatusCell({ p }: { p: PaymentRow }) {
    return <span className="row">
        <Badge size="sm" status={p.status}>{t(`billing.payment_status.${p.status}`)}</Badge>
        {p.deposit && p.kind === 'payment' && <Badge size="sm" status="deposit">{t('billing.payment.deposit_short')}</Badge>}
    </span>;
}

/**
 * Payments and refunds of a reservation. compact: the "Payment Information" card of the detail
 * page (date, method, reference, amount, status); otherwise the full list with refunds.
 */
export default function PaymentsTab({ reservation, onChanged, compact }: BillingProps) {
    const [data, setData] = useState<PaymentsData | null>(null);
    const [failed, setFailed] = useState<ApiError | null>(null);
    const [adding, setAdding] = useState(false);
    const [refunding, setRefunding] = useState<PaymentRow | null>(null);
    const cur = reservation.currency;

    const load = useCallback(() => {
        setFailed(null);
        http.get<PaymentsData>(billingApi(reservation.id, '/payments')).then(setData).catch((e: ApiError) => setFailed(e));
    }, [reservation.id]);
    useEffect(load, [load, reservation.grand_total]);
    const changed = () => { load(); onChanged?.(); };

    if (failed) {
        if (failed.status === 403) return compact ? <p className="billing-note">{t('billing.no_access')}</p> : null;
        return <Alert tone="danger"><div className="row-between"><span>{failed.message}</span><Button size="sm" icon="refresh" onClick={load}>{t('billing.retry')}</Button></div></Alert>;
    }
    if (!data) return <div className="skeleton" style={{ height: compact ? 80 : 160 }} />;

    if (compact) {
        return data.rows.length === 0
            ? <p className="billing-note">{t('billing.payment.none')}</p>
            : <div className="table-scroll"><table className="table table-compact pay-compact">
                <thead><tr><th>{t('billing.fields.date')}</th><th>{t('billing.fields.method_short')}</th><th>{t('billing.fields.reference')}</th><th className="num">{t('billing.fields.amount')}</th><th>{t('billing.fields.status')}</th></tr></thead>
                <tbody>{data.rows.map((p) => (
                    <tr key={p.id}>
                        <td>{date(p.date)}</td>
                        <td>{p.kind === 'refund' ? `${t('billing.payment.refund')} · ` : ''}{methodLabel(p)}</td>
                        <td><span className="cell-clip" title={p.reference ?? ''}>{p.reference ?? '—'}</span></td>
                        <td className="num">{fmt(p.kind === 'refund' ? `-${p.amount}` : p.amount, cur)}</td>
                        <td><Badge size="sm" status={p.status}>{t(`billing.payment_status.${p.status}`)}</Badge></td>
                    </tr>
                ))}</tbody>
            </table></div>;
    }

    const s = data.summary;
    return (
        <Card title={t('billing.payment.list_title')} actions={data.can.manage && <Button size="sm" variant="outline" icon="plus" onClick={() => setAdding(true)}>{t('billing.payment.add')}</Button>}>
            <div className="billing-totals">
                <div><span>{t('billing.summary.total')}</span><strong className="num">{fmt(s.total, cur)}</strong></div>
                <div><span>{t('billing.summary.paid')}</span><strong className="num">{fmt(s.paid, cur)}</strong></div>
                <div className={isPositive(s.balance) ? 'due' : undefined}><span>{t('billing.summary.balance')}</span><strong className="num">{fmt(s.balance, cur)}</strong></div>
            </div>
            {data.rows.length === 0
                ? <EmptyState icon="wallet" title={t('billing.payment.none')} text={data.can.manage ? t('billing.payment.none_hint') : undefined} />
                : <div className="table-scroll"><table className="table">
                    <thead><tr>
                        <th>{t('billing.fields.date')}</th><th>{t('billing.fields.type')}</th><th>{t('billing.fields.method')}</th><th>{t('billing.fields.reference')}</th>
                        <th>{t('billing.fields.received_by')}</th><th className="num">{t('billing.fields.amount')}</th><th>{t('billing.fields.status')}</th><th className="col-actions" />
                    </tr></thead>
                    <tbody>{data.rows.map((p) => (
                        <tr key={p.id}>
                            <td title={dateTime(p.date)}>{date(p.date)}</td>
                            <td>{p.kind === 'refund' ? t('billing.payment.refund') : t('billing.payment.payment')}</td>
                            <td>{methodLabel(p)}</td>
                            <td><span className="cell-clip" title={[p.reference, p.notes, p.failure].filter(Boolean).join(' · ')}>{p.reference ?? p.notes ?? '—'}</span></td>
                            <td>{p.received_by ?? '—'}</td>
                            <td className="num">{fmt(p.kind === 'refund' ? `-${p.amount}` : p.amount, cur)}</td>
                            <td><StatusCell p={p} /></td>
                            <td className="col-actions">{data.can.manage && p.kind === 'payment' && isPositive(p.refundable)
                                && <RowMenu items={[{ label: t('billing.payment.refund_action'), icon: 'repeat', onClick: () => setRefunding(p) }]} />}</td>
                        </tr>
                    ))}</tbody>
                </table></div>}
            {adding && <AddPaymentModal reservation={reservation} open onClose={() => setAdding(false)} onSaved={() => { setAdding(false); changed(); }} />}
            {refunding && <RefundModal payment={refunding} reservationId={reservation.id} currency={cur} methods={['cash', 'card', 'upi', 'bank_transfer', 'other']}
                onClose={() => setRefunding(null)} onDone={() => { setRefunding(null); changed(); }} />}
        </Card>
    );
}

function RefundModal({ payment, reservationId, currency, methods, onClose, onDone }: {
    payment: PaymentRow; reservationId: string; currency: string; methods: string[]; onClose: () => void; onDone: () => void;
}) {
    const [form, setForm] = useState({ amount: payment.refundable, method: payment.method === 'gateway' ? '' : payment.method, reference: '', notes: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const key = useRef(newKey());
    const gateway = payment.method === 'gateway';

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await submit(() => http.post<{ message: string }>(billingApi(reservationId, `/payments/${payment.id}/refund`), {
            amount: form.amount, method: gateway ? null : form.method, reference: form.reference || null, notes: form.notes, idempotency_key: key.current,
        }), setError);
        setBusy(false);
        if (res) onDone();
    };

    return (
        <Modal open title={t('billing.payment.refund_title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="danger" icon="repeat" loading={busy} onClick={save}>{t('billing.payment.refund_action')}</Button>
        </>}>
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); save(); }}>
                <p className="span-12 muted">{t('billing.payment.refund_intro', { amount: fmt(payment.refundable, currency) })}</p>
                <Input fieldClass="span-6" type="number" min={0.01} max={Number(payment.refundable)} step="0.01" label={t('billing.fields.amount')} required suffix={currency} className="num" autoFocus
                    value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} error={error?.field('amount')} />
                {gateway
                    ? <div className="span-6"><Alert tone="info">{t('billing.payment.refund_gateway')}</Alert></div>
                    : <Select fieldClass="span-6" label={t('billing.fields.method')} value={form.method} options={methods.map((m) => ({ value: m, label: t(`billing.methods.${m}`) }))}
                        onChange={(e) => setForm({ ...form, method: e.target.value })} error={error?.field('method')} />}
                {!gateway && <Input fieldClass="span-12" label={t('billing.fields.reference')} optional maxLength={100} value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} />}
                <Textarea fieldClass="span-12" label={t('billing.fields.reason')} required rows={2} maxLength={255} value={form.notes}
                    onChange={(e) => setForm({ ...form, notes: e.target.value })} error={error?.field('notes')} />
                <button type="submit" hidden />
            </form>
        </Modal>
    );
}
