import { useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Button, Input, Modal, Select } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { BillingProps } from '../BillingSlot';
import { billingApi, fmt, loadOptions, newKey, submit, type BillingOptions } from './shared';

interface Row { service_id: string; description: string; quantity: string; unit_price: string; tax_category: string }
const blank = (): Row => ({ service_id: '', description: '', quantity: '1', unit_price: '', tax_category: 'food' });

/** A bill from the restaurant, bar or another outlet: several items, one bill number, each item taxed by its own category. */
export default function AddBillModal({ reservation, open = true, onClose, onSaved, onChanged }: BillingProps) {
    const [options, setOptions] = useState<BillingOptions | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [department, setDepartment] = useState('restaurant');
    const [reference, setReference] = useState('');
    const [rows, setRows] = useState<Row[]>([blank()]);
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const key = useRef(newKey().slice(0, 36));
    const cur = reservation.currency;

    const load = () => { setFailed(null); loadOptions(reservation.id).then(setOptions).catch((e: ApiError) => setFailed(e.message)); };
    useEffect(load, [reservation.id]);

    const setRow = (i: number, patch: Partial<Row>) => setRows((l) => l.map((r, k) => (k === i ? { ...r, ...patch } : r)));
    const pick = (i: number, id: string) => {
        const s = options?.services.find((x) => x.id === id);
        setRow(i, s ? { service_id: id, description: '', unit_price: s.price, tax_category: s.tax_category ?? '' } : { service_id: '' });
    };
    const services = (options?.services ?? []).filter((s) => s.department === department);
    const categories = [{ value: '', label: t('billing.charge.no_tax') }, ...(options?.tax_categories ?? []).map((c) => ({ value: c.value, label: c.label }))];
    const total = useMemo(() => rows.reduce((n, r) => n + Number(r.quantity || 0) * Number(r.unit_price || 0), 0), [rows]);

    const save = async () => {
        setBusy(true);
        setError(null);
        const body = {
            department, reference: reference || null, idempotency_key: key.current,
            lines: rows.filter((r) => r.service_id || r.description || r.unit_price).map((r) => ({
                service_id: r.service_id || null, description: r.description || null, quantity: r.quantity || '1', unit_price: r.unit_price || null, tax_category: r.tax_category || null,
            })),
        };
        const res = await submit(() => http.post<{ message: string }>(billingApi(reservation.id, '/folio/bill'), body), setError);
        setBusy(false);
        if (res) { onChanged?.(); onSaved?.(); }
    };
    const err = (k: string) => error?.field(k);
    const firstError = error ? Object.values(error.fields ?? {})[0]?.[0] : undefined;

    return (
        <Modal open={open} size="lg" title={t('billing.bill.title')} onClose={() => onClose?.()} footer={<>
            <Button onClick={() => onClose?.()}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="receipt" loading={busy} disabled={!options} onClick={save}>{t('billing.bill.post')}</Button>
        </>}>
            {failed ? <Alert tone="danger"><div className="row-between"><span>{failed}</span><Button size="sm" onClick={load}>{t('billing.retry')}</Button></div></Alert>
                : !options ? <div className="skeleton" style={{ height: 220 }} />
                    : <div className="stack">
                        <p className="muted text-sm">{t('billing.bill.hint')}</p>
                        <div className="form-grid">
                            <Select fieldClass="span-6" label={t('billing.charge.department')} value={department} options={options.departments.map((d) => ({ value: d, label: t(`billing.departments.${d}`) }))}
                                onChange={(e) => setDepartment(e.target.value)} error={err('department')} />
                            <Input fieldClass="span-6" label={t('billing.charge.reference')} optional maxLength={40} value={reference} onChange={(e) => setReference(e.target.value)} hint={t('billing.charge.reference_hint')} error={err('reference')} />
                        </div>
                        <div className="edit-rows">
                            {rows.map((r, i) => (
                                <div key={i} className="form-grid bill-row">
                                    <Select fieldClass="span-4" label={i === 0 ? t('billing.charge.service') : undefined} aria-label={t('billing.charge.service')} value={r.service_id} placeholder={t('billing.charge.custom_item')}
                                        options={services.map((s) => ({ value: s.id, label: `${s.name} · ${fmt(s.price, cur)}` }))} onChange={(e) => pick(i, e.target.value)} />
                                    {!r.service_id && <Input fieldClass="span-3" label={i === 0 ? t('billing.fields.description') : undefined} aria-label={t('billing.fields.description')} maxLength={190} value={r.description}
                                        onChange={(e) => setRow(i, { description: e.target.value })} error={err(`lines.${i}.description`)} />}
                                    <Input fieldClass="span-1" type="number" min={0.01} step="0.01" label={i === 0 ? t('billing.fields.quantity') : undefined} aria-label={t('billing.fields.quantity')} className="num" value={r.quantity}
                                        onChange={(e) => setRow(i, { quantity: e.target.value })} error={err(`lines.${i}.quantity`)} />
                                    <Input fieldClass="span-2" type="number" min={0} step="0.01" label={i === 0 ? t('billing.fields.unit_price') : undefined} aria-label={t('billing.fields.unit_price')} className="num" value={r.unit_price}
                                        onChange={(e) => setRow(i, { unit_price: e.target.value })} error={err(`lines.${i}.unit_price`)} />
                                    <Select fieldClass="span-2" label={i === 0 ? t('billing.fields.tax_category') : undefined} aria-label={t('billing.fields.tax_category')} value={r.tax_category} options={categories}
                                        onChange={(e) => setRow(i, { tax_category: e.target.value })} />
                                    <div className="span-12" style={{ display: 'none' }} />
                                    {rows.length > 1 && <Button size="sm" variant="ghost" icon="trash" aria-label={t('ui.delete')} onClick={() => setRows((l) => l.filter((_, k) => k !== i))} />}
                                </div>
                            ))}
                        </div>
                        <div className="row-between">
                            <Button size="sm" variant="outline" icon="plus" onClick={() => setRows((l) => [...l, blank()])}>{t('billing.bill.add_item')}</Button>
                            <span className="billing-preview"><span className="muted">{t('billing.charge.amount_before_tax')}</span> <strong className="num">{fmt(total, cur)}</strong></span>
                        </div>
                        {firstError && !firstError.includes('lines') && <Alert tone="danger">{firstError}</Alert>}
                    </div>}
        </Modal>
    );
}
