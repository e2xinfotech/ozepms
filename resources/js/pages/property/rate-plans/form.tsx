import { useState } from 'react';
import { Alert, Button, Checkbox, FormSection, Input, LinkButton, PageHeader, Select, Textarea, Toggle, toast, type Option } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, currency, errorsUnder, fieldError, MoneyInput, OccupancyEditor, price, type OccupancyRule } from '../_accommodation/shared';
import { MealPlanModal } from './_components/MealPlanModal';
import { PolicyModal, type PolicyOption } from './_components/PolicyModal';
import { PolicyBadge, PolicyRules } from './_components/RatePlanBits';
import type { RatePlanDetail } from './_components/types';

interface RoomTypeOption { value: string; label: string; name: string; code: string; base_adults: number; is_active: boolean }
interface Props {
    rate_plan: RatePlanDetail | null;
    options: {
        meal_plans: Option[]; policies: PolicyOption[]; payment_types: Option[]; room_types: RoomTypeOption[];
        rate_plans: { value: string; label: string; is_active: boolean }[]; age_bands: Option[];
    };
}

interface Link {
    room_type_id: string; enabled: boolean; pricing_mode: 'manual' | 'derived'; default_price: string;
    parent_rate_plan_id: string; adjust_type: string; adjust_value: string; occupancy_rules: OccupancyRule[]; open: boolean; base_price: string | null;
}

/** Create / edit a rate plan and link it to room types (products) with manual or derived prices. */
function RatePlanForm({ rate_plan: rp, options }: Props) {
    const editing = rp !== null;
    const [data, setData] = useState({
        code: rp?.code ?? '', name: rp?.name ?? '', description: rp?.description ?? '',
        meal_plan: rp?.meal_plan?.code ?? 'RO', cancellation_policy: rp?.cancellation_policy?.code ?? (options.policies[0]?.value ?? ''),
        payment_type: rp?.payment_type ?? 'pay_at_property', deposit_value: rp?.deposit_value ?? '',
        default_min_los: rp?.min_los ?? 1, default_max_los: rp?.default_max_los ? String(rp.default_max_los) : '',
        min_advance_days: rp?.min_advance_days !== null && rp?.min_advance_days !== undefined ? String(rp.min_advance_days) : '',
        max_advance_days: rp?.max_advance_days !== null && rp?.max_advance_days !== undefined ? String(rp.max_advance_days) : '',
        sell_on_pms: rp?.sell_on_pms ?? true, sell_on_booking_engine: rp?.sell_on_booking_engine ?? true, sell_on_channels: rp?.sell_on_channels ?? true,
        is_active: rp?.is_active ?? true, is_default: rp?.is_default ?? false,
    });
    const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));
    const [policies, setPolicies] = useState(options.policies);
    const [mealPlans, setMealPlans] = useState(options.meal_plans);
    const [mealModal, setMealModal] = useState(false);
    const [policyModal, setPolicyModal] = useState<{ policy: PolicyOption | null } | null>(null);
    const [links, setLinks] = useState<Link[]>(() => options.room_types.map((r) => {
        const p = rp?.products.find((x) => x.room_type === r.value);
        return {
            room_type_id: r.value, enabled: p?.is_active ?? false, pricing_mode: p?.pricing_mode ?? 'manual', default_price: p?.default_price ?? '',
            parent_rate_plan_id: p?.parent_rate_plan ?? '', adjust_type: p?.adjust_type ?? 'percent', adjust_value: p?.adjust_value ? String(Number(p.adjust_value)) : '-10',
            occupancy_rules: p?.occupancy_rules ?? [], open: false, base_price: p?.base_price ?? null,
        };
    }));
    const [bulk, setBulk] = useState({ parent: '', type: 'percent', value: '-10' });
    const [error, setError] = useState<ApiError | null>(null);
    const [saving, setSaving] = useState(false);
    const err = (k: string) => fieldError(error, k);
    const setLink = (i: number, patch: Partial<Link>) => setLinks((l) => l.map((x, j) => (j === i ? { ...x, ...patch } : x)));
    const parents = options.rate_plans.filter((p) => p.value !== rp?.id);
    const policy = policies.find((p) => p.value === data.cancellation_policy);
    const roomType = (id: string) => options.room_types.find((r) => r.value === id);
    const nullable = (v: string) => (v === '' ? null : Number(v));
    // First-time setup: property → rate plan → room types.
    const inSetup = new URLSearchParams(window.location.search).get('onboarding') === '1';

    const deriveAll = () => {
        if (!bulk.parent) return;
        setLinks((l) => l.map((x) => (x.enabled ? { ...x, pricing_mode: 'derived', parent_rate_plan_id: bulk.parent, adjust_type: bulk.type, adjust_value: bulk.value } : x)));
    };

    const save = async () => {
        setSaving(true);
        setError(null);
        const body = {
            ...data,
            description: data.description || null,
            deposit_value: ['deposit_percent', 'deposit_nights'].includes(data.payment_type) && data.deposit_value !== '' ? data.deposit_value : null,
            default_max_los: nullable(data.default_max_los), min_advance_days: nullable(data.min_advance_days), max_advance_days: nullable(data.max_advance_days),
            room_types: links.filter((l) => l.enabled || rp?.products.some((p) => p.room_type === l.room_type_id && p.is_active)).map((l) => ({
                room_type_id: l.room_type_id, enabled: l.enabled, pricing_mode: l.pricing_mode,
                default_price: l.pricing_mode === 'manual' && l.default_price !== '' ? l.default_price : null,
                parent_rate_plan_id: l.pricing_mode === 'derived' ? l.parent_rate_plan_id || null : null,
                adjust_type: l.pricing_mode === 'derived' ? l.adjust_type : null,
                adjust_value: l.pricing_mode === 'derived' ? l.adjust_value : null,
                occupancy_rules: l.occupancy_rules,
            })),
        };
        const res = await act(() => editing
            ? http.put<{ message: string; rate_plan: RatePlanDetail }>(propertyApiUrl(`/rate-plans/${rp.id}`), body)
            : http.post<{ message: string; rate_plan: RatePlanDetail }>(propertyApiUrl('/rate-plans'), body), (e) => { setError(e); toast.error(e.message); });
        setSaving(false);
        // During first-time setup the next step is the room types.
        if (res) window.location.href = inSetup && !editing ? propertyUrl('/room-types/new?onboarding=1') : propertyUrl(`/rate-plans?selected=${res.rate_plan.id}`);
    };

    return (
        <div className="content">
            <PageHeader back={propertyUrl('/rate-plans')} title={editing ? `${t('rates.edit_rate_plan')}: ${rp.name}` : t('rates.add_rate_plan')} description={t('rates.description')} />
            <div className="form-page">
                {inSetup && !editing && <Alert tone="info"><strong>{t('rates.setup.title')}</strong> {t('rates.setup.text')}</Alert>}
                {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}

                <FormSection title={t('rates.sections.basic')} description={t('rates.sections.basic_desc')}>
                    <Input fieldClass="span-6" label={t('rates.fields.name')} required value={data.name} maxLength={120} onChange={(e) => set('name', e.target.value)} error={err('name')} />
                    <Input fieldClass="span-3" label={t('rates.fields.code')} required value={data.code} maxLength={20} onChange={(e) => set('code', e.target.value.toUpperCase())} error={err('code')} />
                    <div className="field span-3">
                        <span className="field-label">{t('rates.fields.status')}</span>
                        <Toggle checked={data.is_active} onChange={(v) => set('is_active', v)} label={t(`ui.status.${data.is_active ? 'active' : 'inactive'}`)} />
                        {err('is_active') && <div className="field-error">{err('is_active')}</div>}
                    </div>
                    <Textarea fieldClass="span-9" label={t('rates.fields.description')} optional rows={2} maxLength={2000} value={data.description} onChange={(e) => set('description', e.target.value)} />
                    <div className="field span-3">
                        <span className="field-label">&nbsp;</span>
                        <Checkbox checked={data.is_default} onChange={(e) => set('is_default', e.target.checked)} label={t('rates.fields.is_default')} />
                        {err('is_default') && <div className="field-error">{err('is_default')}</div>}
                    </div>
                </FormSection>

                <FormSection title={t('rates.sections.policy')}>
                    <Select fieldClass="span-4" label={t('rates.fields.meal_plan')} required value={data.meal_plan} options={mealPlans} onChange={(e) => set('meal_plan', e.target.value)} error={err('meal_plan')}
                        hint={<button type="button" className="link-button" title={t('rates.meal_plan_custom.hint')} onClick={() => setMealModal(true)}>+ {t('rates.meal_plan_custom.title')}</button>} />
                    <Select fieldClass="span-5" label={t('rates.fields.policy')} required value={data.cancellation_policy} options={policies.map((p) => ({ value: p.value, label: p.label }))}
                        onChange={(e) => set('cancellation_policy', e.target.value)} error={err('cancellation_policy')} />
                    <div className="field span-3">
                        <span className="field-label">&nbsp;</span>
                        <div className="row">
                            <Button variant="outline" icon="plus" onClick={() => setPolicyModal({ policy: null })}>{t('rates.policy.new')}</Button>
                            {policy && <Button variant="ghost" icon="pencil" title={t('rates.policy.edit')} aria-label={t('rates.policy.edit')} onClick={() => setPolicyModal({ policy })} />}
                        </div>
                    </div>
                    {policy && <div className="span-12 info-box">
                        <div className="row-between"><strong>{policy.label}</strong><PolicyBadge refundable={policy.refundable} /></div>
                        <PolicyRules rules={policy.rules} currency={currency()} />
                    </div>}
                    <Select fieldClass="span-6" label={t('rates.fields.payment_type')} value={data.payment_type} options={options.payment_types} onChange={(e) => set('payment_type', e.target.value)} />
                    <Input fieldClass="span-3" type="number" min={0} step="0.01" label={t('rates.fields.deposit_value')}
                        disabled={!['deposit_percent', 'deposit_nights'].includes(data.payment_type)} value={data.deposit_value}
                        suffix={data.payment_type === 'deposit_percent' ? '%' : data.payment_type === 'deposit_nights' ? t('rates.nights_suffix') : undefined}
                        onChange={(e) => set('deposit_value', e.target.value)} error={err('deposit_value')} />
                </FormSection>

                <FormSection title={t('rates.sections.restrictions')}>
                    <Input fieldClass="span-3" type="number" min={1} max={365} label={t('rates.fields.min_los')} required value={data.default_min_los}
                        onChange={(e) => set('default_min_los', Number(e.target.value))} error={err('default_min_los')} />
                    <Input fieldClass="span-3" type="number" min={1} max={365} label={t('rates.fields.max_los')} optional value={data.default_max_los}
                        onChange={(e) => set('default_max_los', e.target.value)} error={err('default_max_los')} />
                    <Input fieldClass="span-3" type="number" min={0} max={730} label={t('rates.fields.min_advance')} optional value={data.min_advance_days}
                        onChange={(e) => set('min_advance_days', e.target.value)} error={err('min_advance_days')} />
                    <Input fieldClass="span-3" type="number" min={0} max={730} label={t('rates.fields.max_advance')} optional value={data.max_advance_days}
                        onChange={(e) => set('max_advance_days', e.target.value)} error={err('max_advance_days')} />
                </FormSection>

                <FormSection title={t('rates.sections.distribution')}>
                    <div className="span-12 check-grid">
                        <Checkbox checked={data.sell_on_pms} onChange={(e) => set('sell_on_pms', e.target.checked)} label={t('rates.channels.pms')} />
                        <Checkbox checked={data.sell_on_booking_engine} onChange={(e) => set('sell_on_booking_engine', e.target.checked)} label={t('rates.channels.booking_engine')} />
                        <Checkbox checked={data.sell_on_channels} onChange={(e) => set('sell_on_channels', e.target.checked)} label={t('rates.channels.channels')} />
                    </div>
                </FormSection>

                <FormSection title={t('rates.sections.room_types')} description={t('rates.sections.room_types_desc')}>
                    {parents.length > 0 && links.length > 0 && <>
                        <Select fieldClass="span-4" label={t('rates.pricing.derive_all')} value={bulk.parent} placeholder={t('rates.fields.parent')} options={parents}
                            onChange={(e) => setBulk({ ...bulk, parent: e.target.value })} />
                        <Select fieldClass="span-3" label={t('rates.fields.adjust_type')} value={bulk.type}
                            options={['percent', 'fixed', 'fixed_per_person'].map((v) => ({ value: v, label: t(`rates.adjust_types.${v}`) }))} onChange={(e) => setBulk({ ...bulk, type: e.target.value })} />
                        <Input fieldClass="span-2" type="number" step="0.01" label={t('rates.fields.adjust_value')} value={bulk.value} onChange={(e) => setBulk({ ...bulk, value: e.target.value })} />
                        <div className="field span-3"><span className="field-label">&nbsp;</span><Button variant="outline" icon="repeat" disabled={!bulk.parent} onClick={deriveAll}>{t('rates.pricing.apply_all')}</Button></div>
                    </>}
                    <div className="span-12">
                        {links.length === 0 ? <p className="muted">{t('rates.no_room_types')}</p> : (
                            <div className="table-wrap"><div className="table-scroll">
                                <table className="table product-table">
                                    <colgroup><col className="col-plan" /><col className="col-enabled" /><col className="col-mode" /><col /><col className="col-occ" /></colgroup>
                                    <thead><tr>
                                        <th>{t('rooms.room_type')}</th><th>{t('rooms.enabled')}</th><th>{t('rates.fields.pricing')}</th>
                                        <th>{t('rates.fields.price')}</th><th className="th-wrap">{t('rates.sections.occupancy')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {links.map((l, i) => [
                                            <tr key={l.room_type_id}>
                                                <td><span className="cell-main">{roomType(l.room_type_id)?.name}</span> <span className="muted">({roomType(l.room_type_id)?.code})</span></td>
                                                <td><Checkbox aria-label={t('rooms.enabled')} checked={l.enabled} onChange={(e) => setLink(i, { enabled: e.target.checked })} /></td>
                                                <td><Select size="sm" aria-label={t('rates.fields.pricing')} disabled={!l.enabled} value={l.pricing_mode}
                                                    options={[{ value: 'manual', label: t('rooms.manual') }, { value: 'derived', label: t('rooms.derived') }]}
                                                    onChange={(e) => setLink(i, { pricing_mode: e.target.value as Link['pricing_mode'] })} /></td>
                                                <td>
                                                    {l.pricing_mode === 'manual'
                                                        ? <MoneyInput value={l.default_price} onChange={(v) => setLink(i, { default_price: v })} error={err(`room_types.${i}.default_price`)} />
                                                        : <div className="inline-fields">
                                                            <Select size="sm" aria-label={t('rates.fields.parent')} disabled={!l.enabled} value={l.parent_rate_plan_id} placeholder={t('rates.fields.parent')}
                                                                options={parents} onChange={(e) => setLink(i, { parent_rate_plan_id: e.target.value })} error={err(`room_types.${i}.parent_rate_plan_id`)} />
                                                            <Select size="sm" aria-label={t('rates.fields.adjust_type')} value={l.adjust_type}
                                                                options={['percent', 'fixed', 'fixed_per_person'].map((v) => ({ value: v, label: t(`rates.adjust_types.${v}`) }))}
                                                                onChange={(e) => setLink(i, { adjust_type: e.target.value })} />
                                                            <Input size="sm" type="number" step="0.01" aria-label={t('rates.fields.adjust_value')} value={l.adjust_value} className="num"
                                                                onChange={(e) => setLink(i, { adjust_value: e.target.value })} error={err(`room_types.${i}.adjust_value`)} />
                                                        </div>}
                                                    {l.base_price && l.pricing_mode === 'derived' && <div className="field-hint num">{t('rates.current_price', { price: price(l.base_price) })}</div>}
                                                </td>
                                                <td><Button size="sm" variant="ghost" disabled={!l.enabled} title={t('rates.sections.occupancy')} iconRight={l.open ? 'chevron-up' : 'chevron-down'} onClick={() => setLink(i, { open: !l.open })}>{l.occupancy_rules.length}</Button></td>
                                            </tr>,
                                            l.open && l.enabled ? (
                                                <tr key={`${l.room_type_id}-occ`} className="sub-row"><td colSpan={5}>
                                                    <p className="muted text-sm" style={{ marginBottom: 8 }}>{t('rates.sections.occupancy_desc')}</p>
                                                    <OccupancyEditor rules={l.occupancy_rules} onChange={(rules) => setLink(i, { occupancy_rules: rules })}
                                                        ageBands={options.age_bands} errorPrefix={`room_types.${i}.occupancy_rules`} error={error} />
                                                </td></tr>
                                            ) : null,
                                        ])}
                                    </tbody>
                                </table>
                            </div></div>
                        )}
                        {errorsUnder(error, 'room_types').slice(0, 1).map((m) => <div key={m} className="field-error" style={{ marginTop: 8 }}>{m}</div>)}
                    </div>
                </FormSection>

                <div className="form-footer">
                    <span className="spacer" />
                    <LinkButton href={propertyUrl('/rate-plans')}>{t('ui.cancel')}</LinkButton>
                    <Button variant="primary" icon="save" loading={saving} onClick={save}>{editing ? t('ui.save_changes') : t('rates.add_rate_plan')}</Button>
                </div>
            </div>
            {mealModal && <MealPlanModal onClose={() => setMealModal(false)}
                onSaved={(code, list) => { setMealPlans(list); set('meal_plan', code); setMealModal(false); }} />}
            {policyModal && <PolicyModal policy={policyModal.policy} onClose={() => setPolicyModal(null)}
                onSaved={(code, list) => { setPolicies(list); set('cancellation_policy', code); setPolicyModal(null); }} />}
        </div>
    );
}

createPage(RatePlanForm);
