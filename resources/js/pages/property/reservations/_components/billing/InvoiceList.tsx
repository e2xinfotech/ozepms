import { useCallback, useEffect, useState } from 'react';
import { Alert, Badge, Button, Card, EmptyState, Input, Modal, RowMenu, Textarea } from '@/components/ui';
import { date } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { BillingProps } from '../BillingSlot';
import { billingApi, fmt, isPositive, submit, type InvoiceRow, type InvoicesData } from './shared';
import { propertyApiUrl } from '@/lib/page';
import { SendInvoiceDialog } from '@/components/property/SendInvoiceDialog';

/** Tax invoices and credit notes of the reservation; issue an invoice, cancel one with a credit note. */
export default function InvoiceList({ reservation, onChanged }: BillingProps) {
    const [data, setData] = useState<InvoicesData | null>(null);
    const [failed, setFailed] = useState<ApiError | null>(null);
    const [issuing, setIssuing] = useState(false);
    const [cancelling, setCancelling] = useState<InvoiceRow | null>(null);
    const [emailing, setEmailing] = useState<InvoiceRow | null>(null);

    const load = useCallback(() => {
        setFailed(null);
        http.get<InvoicesData>(billingApi(reservation.id, '/invoices')).then(setData).catch((e: ApiError) => setFailed(e));
    }, [reservation.id]);
    useEffect(load, [load, reservation.grand_total]);
    const changed = () => { load(); onChanged?.(); };

    if (failed) {
        if (failed.status === 403) return null;
        return <Alert tone="danger"><div className="row-between"><span>{failed.message}</span><Button size="sm" icon="refresh" onClick={load}>{t('billing.retry')}</Button></div></Alert>;
    }
    if (!data) return <Card title={t('billing.invoice.list_title')}><div className="skeleton" style={{ height: 100 }} /></Card>;

    return (
        <Card title={t('billing.invoice.list_title')} actions={data.can.manage && data.billable
            && <Button size="sm" variant="outline" icon="file-text" onClick={() => setIssuing(true)}>{t('billing.invoice.issue')}</Button>}>
            {data.rows.length === 0
                ? <EmptyState icon="file-text" title={t('billing.invoice.none')} text={t('billing.invoice.none_hint')} />
                : <div className="table-scroll"><table className="table">
                    <thead><tr>
                        <th>{t('billing.invoice.number')}</th><th>{t('billing.fields.type')}</th><th>{t('billing.fields.date')}</th><th>{t('billing.invoice.bill_to')}</th>
                        <th className="num">{t('billing.invoice.taxable')}</th><th className="num">{t('billing.fields.tax')}</th><th className="num">{t('billing.fields.total')}</th>
                        <th>{t('billing.fields.status')}</th><th className="col-actions" />
                    </tr></thead>
                    <tbody>{data.rows.map((i) => (
                        <tr key={i.id}>
                            <td><a href={i.url} target="_blank" rel="noopener" className="cell-main link">{i.number}</a>{i.original && <span className="cell-sub">{t('billing.invoice.against', { number: i.original })}</span>}</td>
                            <td><Badge size="sm" status={i.type}>{t(`billing.invoice.types.${i.type}`)}</Badge></td>
                            <td>{date(i.date)}</td>
                            <td><span className="cell-main">{i.bill_to}</span>{i.tax_no && <span className="cell-sub">GSTIN {i.tax_no}</span>}</td>
                            <td className="num">{fmt(i.taxable, i.currency)}</td>
                            <td className="num">{fmt(i.tax, i.currency)}</td>
                            <td className="num strong">{fmt(i.total, i.currency)}</td>
                            <td>{i.cancelled ? <Badge size="sm" status="cancelled">{t('billing.invoice.cancelled')}</Badge> : <Badge size="sm" status="active">{t('billing.invoice.valid')}</Badge>}</td>
                            <td className="col-actions"><RowMenu items={[
                                { label: t('billing.invoice.view'), icon: 'printer', href: i.url },
                                ...(data.can.manage ? [{ label: t('mailsettings.invoice_send'), icon: 'mail', onClick: () => setEmailing(i) }] : []),
                                ...(data.can.manage && i.type === 'tax_invoice' && !i.cancelled ? [{ label: t('billing.invoice.cancel'), icon: 'circle-x', danger: true, onClick: () => setCancelling(i) }] : []),
                            ]} /></td>
                        </tr>
                    ))}</tbody>
                </table></div>}
            {isPositive(data.pending_room_charges) && <p className="muted text-sm">{t('billing.invoice.pending_hint', { amount: fmt(data.pending_room_charges, reservation.currency) })}</p>}
            {issuing && <IssueModal reservationId={reservation.id} onClose={() => setIssuing(false)} onDone={() => { setIssuing(false); changed(); }} />}
            {emailing && <SendInvoiceDialog invoiceId={emailing.id} number={emailing.number} onClose={() => setEmailing(null)} />}
            {cancelling && <CancelModal invoice={cancelling} onClose={() => setCancelling(null)} onDone={() => { setCancelling(null); changed(); }} />}
        </Card>
    );
}

function IssueModal({ reservationId, onClose, onDone }: { reservationId: string; onClose: () => void; onDone: () => void }) {
    const [form, setForm] = useState({ bill_to_name: '', bill_to_tax_no: '', bill_to_address: '', bill_to_state: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
    const save = async () => {
        setBusy(true);
        setError(null);
        const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() || null]));
        const res = await submit(() => http.post<{ message: string; invoice: InvoiceRow }>(billingApi(reservationId, '/invoices'), body), setError);
        setBusy(false);
        if (res) {
            onDone();
            window.open(res.invoice.url, '_blank', 'noopener');
        }
    };
    return (
        <Modal open title={t('billing.invoice.issue')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="file-text" loading={busy} onClick={save}>{t('billing.invoice.issue')}</Button>
        </>}>
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); save(); }}>
                <p className="span-12 muted">{t('billing.invoice.issue_intro')}</p>
                {error?.field('invoice') && <div className="span-12"><Alert tone="danger">{error.field('invoice')}</Alert></div>}
                <Input fieldClass="span-6" label={t('billing.invoice.company')} optional maxLength={190} autoFocus value={form.bill_to_name} onChange={(e) => set('bill_to_name', e.target.value)} error={error?.field('bill_to_name')} />
                <Input fieldClass="span-6" label={t('billing.invoice.gstin')} optional maxLength={30} placeholder="27AAACA1234A1Z5" value={form.bill_to_tax_no}
                    onChange={(e) => set('bill_to_tax_no', e.target.value.toUpperCase())} error={error?.field('bill_to_tax_no')} />
                <Textarea fieldClass="span-8" label={t('billing.invoice.address')} optional rows={2} maxLength={500} value={form.bill_to_address} onChange={(e) => set('bill_to_address', e.target.value)} />
                <Input fieldClass="span-4" label={t('billing.invoice.state_code')} optional maxLength={10} placeholder="27" value={form.bill_to_state}
                    onChange={(e) => set('bill_to_state', e.target.value.toUpperCase())} error={error?.field('bill_to_state')} />
                <button type="submit" hidden />
            </form>
        </Modal>
    );
}

export function CancelModal({ invoice, onClose, onDone }: { invoice: { id: string; number: string }; onClose: () => void; onDone: () => void }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await submit(() => http.post<{ message: string }>(propertyApiUrl(`/invoices/${invoice.id}/cancel`), { reason }), setError);
        setBusy(false);
        if (res) onDone();
    };
    return (
        <Modal open title={t('billing.invoice.cancel_title', { number: invoice.number })} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.close')}</Button>
            <Button variant="danger" icon="circle-x" loading={busy} onClick={save}>{t('billing.invoice.cancel')}</Button>
        </>}>
            <form className="stack" onSubmit={(e) => { e.preventDefault(); save(); }}>
                <p>{t('billing.invoice.cancel_intro')}</p>
                {error?.field('invoice') && <Alert tone="danger">{error.field('invoice')}</Alert>}
                <Textarea label={t('billing.fields.reason')} required rows={2} maxLength={255} autoFocus value={reason} onChange={(e) => setReason(e.target.value)} error={error?.field('reason')} />
            </form>
        </Modal>
    );
}

