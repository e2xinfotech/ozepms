import { useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Button, Input, Modal, Segmented, Select, Textarea } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { BillingProps } from '../BillingSlot';
import { billingApi, fmt, loadOptions, newKey, submit, type BillingOptions } from './shared';

type Kind = 'service' | 'adjustment' | 'discount';

/** Posts an extra, an adjustment or a discount to the reservation's folio. */
export default function AddChargeModal({ reservation, open = true, onClose, onSaved, onChanged }: BillingProps & { initialType?: Kind }) {
    const [options, setOptions] = useState<BillingOptions | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [type, setType] = useState<Kind>('service');
    const [form, setForm] = useState({ service_id: '', description: '', quantity: '1', unit_price: '', tax_category: '', discount_mode: 'amount', discount_percent: '', note: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const key = useRef(newKey());
    const cur = reservation.currency;

    const load = () => {
        setFailed(null);
        loadOptions(reservation.id).then(setOptions).catch((e: ApiError) => setFailed(e.message));
    };
    useEffect(load, [reservation.id]);

    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
    const pickService = (id: string) => {
        const s = options?.services.find((x) => x.id === id);
        setForm((f) => ({ ...f, service_id: id, description: s ? '' : f.description, unit_price: s ? s.price : f.unit_price, quantity: s ? s.quantity : f.quantity, tax_category: s?.tax_category ?? f.tax_category }));
    };
    const switchType = (k: string) => {
        setType(k as Kind);
        setError(null);
        setForm((f) => ({ ...f, service_id: '', tax_category: k === 'discount' ? 'accommodation' : k === 'adjustment' ? '' : f.tax_category }));
    };

    const preview = useMemo(() => {
        if (type === 'discount' && form.discount_mode === 'percent') return null;
        const v = Number(form.quantity || 0) * Number(form.unit_price || 0);
        return Number.isFinite(v) ? (type === 'discount' ? -Math.abs(v) : v) : null;
    }, [type, form]);

    const save = async () => {
        setBusy(true);
        setError(null);
        const body: Record<string, unknown> = {
            type, idempotency_key: key.current, description: form.description || null, note: form.note || null,
            tax_category: form.tax_category || null,
        };
        if (type === 'service' && form.service_id) body.service_id = form.service_id;
        if (type === 'discount' && form.discount_mode === 'percent') body.discount_percent = form.discount_percent;
        else { body.unit_price = form.unit_price; body.quantity = type === 'discount' ? '1' : form.quantity; }
        const res = await submit(() => http.post<{ message: string }>(billingApi(reservation.id, '/folio/charges'), body), setError);
        setBusy(false);
        if (res) {
            onChanged?.();
            onSaved?.();
        }
    };
    const close = () => onClose?.();
    const err = (k: string) => error?.field(k);

    const taxOptions = [{ value: '', label: t('billing.charge.no_tax') }, ...(options?.tax_categories ?? []).map((c) => ({ value: c.value, label: c.label }))];

    return (
        <Modal open={open} title={t('billing.charge.title')} onClose={close} footer={<>
            <Button onClick={close}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="plus" loading={busy} disabled={!options} onClick={save}>{t('billing.charge.post')}</Button>
        </>}>
            {failed ? <Alert tone="danger"><div className="row-between"><span>{failed}</span><Button size="sm" onClick={load}>{t('billing.retry')}</Button></div></Alert>
                : !options ? <div className="skeleton" style={{ height: 220 }} />
                    : <form className="stack" onSubmit={(e) => { e.preventDefault(); save(); }}>
                        <Segmented active={type} onChange={switchType} items={(['service', 'adjustment', 'discount'] as const).map((k) => ({ key: k, label: t(`billing.types.${k}`) }))} />
                        <div className="form-grid">
                            {type === 'service' && <Select fieldClass="span-12" label={t('billing.charge.service')} value={form.service_id} autoFocus
                                placeholder={options.services.length ? t('billing.charge.custom_item') : t('billing.charge.no_services')}
                                options={options.services.map((s) => ({ value: s.id, label: `${s.name} · ${fmt(s.price, cur)} · ${t(`billing.posting_rules.${s.posting_rule}`)}` }))}
                                onChange={(e) => pickService(e.target.value)} error={err('service_id')} />}
                            {!(type === 'service' && form.service_id) && <Input fieldClass="span-12" label={t('billing.fields.description')} required={type === 'service'} optional={type !== 'service'}
                                maxLength={190} value={form.description} onChange={(e) => set('description', e.target.value)} error={err('description')}
                                placeholder={t(`billing.charge.placeholder_${type}`)} />}
                            {type === 'discount' && <Select fieldClass="span-12" label={t('billing.charge.discount_mode')} value={form.discount_mode}
                                options={[{ value: 'amount', label: t('billing.charge.discount_amount') }, { value: 'percent', label: t('billing.charge.discount_percent') }]}
                                onChange={(e) => set('discount_mode', e.target.value)} />}
                            {type === 'discount' && form.discount_mode === 'percent'
                                ? <Input fieldClass="span-6" type="number" min={0} max={100} step="0.01" label={t('billing.charge.percent')} required suffix="%" className="num"
                                    value={form.discount_percent} onChange={(e) => set('discount_percent', e.target.value)} error={err('discount_percent')} hint={t('billing.charge.percent_hint')} />
                                : <>
                                    {type !== 'discount' && <Input fieldClass="span-4" type="number" min={0.01} step="0.01" label={t('billing.fields.quantity')} required className="num"
                                        value={form.quantity} onChange={(e) => set('quantity', e.target.value)} error={err('quantity')} />}
                                    <Input fieldClass={type === 'discount' ? 'span-6' : 'span-8'} type="number" step="0.01" min={type === 'adjustment' ? undefined : 0}
                                        label={type === 'discount' ? t('billing.charge.discount_value') : t('billing.fields.unit_price')} required suffix={cur} className="num"
                                        value={form.unit_price} onChange={(e) => set('unit_price', e.target.value)} error={err('unit_price')}
                                        hint={type === 'adjustment' ? t('billing.charge.adjustment_hint') : undefined} />
                                </>}
                            <Select fieldClass={type === 'discount' ? 'span-6' : 'span-12'} label={t('billing.fields.tax_category')} value={form.tax_category} options={taxOptions}
                                onChange={(e) => set('tax_category', e.target.value)} error={err('tax_category')} hint={t('billing.charge.tax_hint')} />
                            <Textarea fieldClass="span-12" label={t('billing.fields.note')} optional rows={2} maxLength={255} value={form.note} onChange={(e) => set('note', e.target.value)} />
                        </div>
                        {preview !== null && <div className="billing-preview row-between"><span>{t('billing.charge.amount_before_tax')}</span><strong className="num">{fmt(preview, cur)}</strong></div>}
                        <button type="submit" hidden />
                    </form>}
        </Modal>
    );
}
