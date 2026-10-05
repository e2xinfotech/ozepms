import { useEffect, useState } from 'react';
import { Badge, Button, Checkbox, ConfirmDialog, EmptyState, Input, Select, SidePanel, Tabs, Textarea, Toggle, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, currency, fieldError } from '../../_accommodation/shared';

export interface TaxOptions {
    kinds: Option[]; tax_types: Option[]; apply_to: Option[]; methods: Option[]; bases: Option[]; component_modes: Option[];
    room_types: Option[]; rate_plans: Option[];
}
interface TaxDetail {
    id: string; name: string; code: string; kind: string; tax_type: string; calc_type: string; rate: string; apply_to: string[];
    description: string | null; slab_min: string | null; slab_max: string | null; component_mode: string; is_inclusive: boolean; is_compound: boolean;
    priority: number; effective_from: string | null; effective_to: string | null; is_active: boolean; is_default_for_new_room_types: boolean;
    include_in_displayed_rate: boolean; room_types: string[]; rate_plans: string[];
}

const CALC: Record<string, [string, string]> = {
    percent: ['percent', 'per_room_night'], fixed_per_night: ['fixed', 'per_room_night'], fixed_per_person_night: ['fixed', 'per_person_night'],
    fixed_per_stay: ['fixed', 'per_stay'], fixed_per_booking: ['fixed', 'per_booking'],
};
const BASIS_CALC: Record<string, string> = { per_room_night: 'fixed_per_night', per_person_night: 'fixed_per_person_night', per_stay: 'fixed_per_stay', per_booking: 'fixed_per_booking' };

const DETAIL_KEYS = ['name', 'code', 'kind', 'rate', 'calc_type', 'apply_to', 'tax_type', 'description'];

export function splitCalc(calcType: string): [string, string] {
    return CALC[calcType] ?? ['percent', 'per_room_night'];
}

const blank = (): TaxDetail => ({
    id: '', name: '', code: '', kind: 'tax', tax_type: 'other', calc_type: 'percent', rate: '', apply_to: ['room_charges'], description: '',
    slab_min: null, slab_max: null, component_mode: 'single', is_inclusive: false, is_compound: false, priority: 50, effective_from: null,
    effective_to: null, is_active: true, is_default_for_new_room_types: false, include_in_displayed_rate: false, room_types: [], rate_plans: [],
});

/** Inline edit panel of Taxes & Fees (design: taxes-fees.png). id === 'new' creates a rule. */
export function TaxPanel({ id, options, onClose, onSaved }: { id: string; options: TaxOptions; onClose: () => void; onSaved: (id: string | null) => void }) {
    const [rule, setRule] = useState<TaxDetail | null>(id === 'new' ? blank() : null);
    const [failed, setFailed] = useState<string | null>(null);
    const [tab, setTab] = useState('details');
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    useEffect(() => {
        let alive = true;
        setError(null);
        setTab('details');
        if (id === 'new') { setRule(blank()); return; }
        setRule(null);
        http.get<{ tax: TaxDetail }>(propertyApiUrl(`/taxes/${id}`))
            .then((res) => alive && setRule({ ...res.tax, rate: String(Number(res.tax.rate)) }))
            .catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id]);

    if (failed) return <SidePanel title={t('taxes.title')} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!rule) return <SidePanel title={t('ui.loading')} onClose={onClose}><div className="sp-section stack"><div className="skeleton" style={{ height: 300 }} /></div></SidePanel>;

    const set = <K extends keyof TaxDetail>(k: K, v: TaxDetail[K]) => setRule({ ...rule, [k]: v });
    const [method, basis] = splitCalc(rule.calc_type);
    const setCalc = (m: string, b: string) => set('calc_type', m === 'percent' ? 'percent' : BASIS_CALC[b] ?? 'fixed_per_night');
    const toggleIn = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
    const err = (k: string) => fieldError(error, k);

    const save = async () => {
        setBusy(true);
        setError(null);
        const body = {
            name: rule.name, code: rule.code, kind: rule.kind, tax_type: rule.tax_type, calc_type: rule.calc_type, rate: rule.rate === '' ? null : rule.rate,
            apply_to: rule.apply_to, description: rule.description || null, slab_min: rule.slab_min || null, slab_max: rule.slab_max || null,
            component_mode: rule.component_mode, is_inclusive: rule.is_inclusive, is_compound: rule.is_compound, priority: Number(rule.priority),
            ...(rule.effective_from ? { effective_from: rule.effective_from } : {}), effective_to: rule.effective_to || null,
            is_active: rule.is_active, is_default_for_new_room_types: rule.is_default_for_new_room_types, include_in_displayed_rate: rule.include_in_displayed_rate,
            room_types: rule.room_types, rate_plans: rule.rate_plans,
        };
        const res = await act(() => rule.id
            ? http.put<{ message: string; tax: TaxDetail }>(propertyApiUrl(`/taxes/${rule.id}`), body)
            : http.post<{ message: string; tax: TaxDetail }>(propertyApiUrl('/taxes'), body), (e) => {
            setError(e);
            const keys = Object.keys(e.fields);
            const on = (list: string[]) => keys.some((k) => list.some((x) => k === x || k.startsWith(x + '.')));
            if (on(DETAIL_KEYS)) setTab('details');
            else if (on(['room_types', 'rate_plans'])) setTab('applies');
            else if (keys.length) setTab('advanced');
        });
        setBusy(false);
        if (res) onSaved(res.tax.id);
    };
    const remove = async () => {
        setBusy(true);
        const res = await act(() => http.delete<{ message: string }>(propertyApiUrl(`/taxes/${rule.id}`)));
        setBusy(false);
        setConfirmDelete(false);
        if (res) onSaved(null);
    };
    const tabError = (keys: string[]) => (error ? keys.some((k) => Object.keys(error.fields).some((f) => f === k || f.startsWith(k + '.'))) : false);

    return (
        <SidePanel title={rule.id ? rule.name : t('taxes.new')} onClose={onClose} headerExtra={rule.id ? <Badge status={rule.is_active ? 'active' : 'inactive'} /> : undefined}>
            <div style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={[
                    { key: 'details', label: t('taxes.panel_tabs.details') + (tabError(DETAIL_KEYS) ? ' •' : '') },
                    { key: 'applies', label: t('taxes.panel_tabs.applies') + (tabError(['room_types', 'rate_plans']) ? ' •' : '') },
                    { key: 'advanced', label: t('taxes.panel_tabs.advanced') + (tabError(['slab_min', 'slab_max', 'component_mode', 'effective_to', 'is_inclusive']) ? ' •' : '') },
                ]} />
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'details' && <div className="form-grid">
                    <Input fieldClass="span-12" label={t('taxes.fields.name')} required value={rule.name} maxLength={80} onChange={(e) => set('name', e.target.value)} error={err('name')} />
                    <Input fieldClass="span-6" label={t('taxes.fields.code')} required value={rule.code} maxLength={30} onChange={(e) => set('code', e.target.value.toUpperCase())} error={err('code')} />
                    <Select fieldClass="span-6" label={t('taxes.fields.type')} required value={rule.kind} options={options.kinds}
                        onChange={(e) => setRule({ ...rule, kind: e.target.value, tax_type: e.target.value === 'service_charge' ? 'service_charge' : rule.tax_type, component_mode: e.target.value === 'tax' ? rule.component_mode : 'single' })} error={err('kind')} />
                    <Input fieldClass="span-6" type="number" min={0} step="0.01" label={t('taxes.fields.rate')} required value={rule.rate} className="num"
                        suffix={method === 'percent' ? '%' : currency()} onChange={(e) => set('rate', e.target.value)} error={err('rate')} />
                    <Select fieldClass="span-6" label={t('taxes.fields.basis')} value={basis} disabled={method === 'percent'} options={options.bases} onChange={(e) => setCalc(method, e.target.value)} />
                    <div className="field span-12">
                        <span className="field-label">{t('taxes.fields.apply_to')}<span className="req">*</span></span>
                        <div className="check-grid">
                            {options.apply_to.map((o) => <Checkbox key={o.value} checked={rule.apply_to.includes(String(o.value))} label={o.label} onChange={() => set('apply_to', toggleIn(rule.apply_to, String(o.value)))} />)}
                        </div>
                        {(err('apply_to') ?? err('apply_to.0')) && <div className="field-error">{err('apply_to') ?? err('apply_to.0')}</div>}
                    </div>
                    <Select fieldClass="span-6" label={t('taxes.fields.method')} required value={method} options={options.methods} onChange={(e) => setCalc(e.target.value, basis)} error={err('calc_type')} />
                    <Select fieldClass="span-6" label={t('taxes.fields.tax_type')} value={rule.tax_type} options={options.tax_types} onChange={(e) => set('tax_type', e.target.value)} />
                    <Textarea fieldClass="span-12" label={t('taxes.fields.description')} optional rows={2} maxLength={500} value={rule.description ?? ''} onChange={(e) => set('description', e.target.value)} />
                    <div className="field span-12">
                        <span className="field-label">{t('taxes.fields.options')}</span>
                        <Checkbox checked={rule.is_default_for_new_room_types} onChange={(e) => set('is_default_for_new_room_types', e.target.checked)} label={t('taxes.fields.default_new')} />
                        <Checkbox checked={rule.include_in_displayed_rate} onChange={(e) => set('include_in_displayed_rate', e.target.checked)} label={t('taxes.fields.include_displayed')} />
                    </div>
                    <div className="field span-12">
                        <span className="field-label">{t('taxes.fields.status')}</span>
                        <Toggle checked={rule.is_active} onChange={(v) => set('is_active', v)} label={t(`ui.status.${rule.is_active ? 'active' : 'inactive'}`)} />
                    </div>
                </div>}
                {tab === 'applies' && <div className="stack">
                    <p className="muted text-sm">{t('taxes.hints.applies')}</p>
                    <div className="field">
                        <span className="field-label">{t('taxes.fields.room_types')}</span>
                        <div className="stack" style={{ gap: 6 }}>{options.room_types.map((o) => <Checkbox key={o.value} checked={rule.room_types.includes(String(o.value))} label={o.label} onChange={() => set('room_types', toggleIn(rule.room_types, String(o.value)))} />)}</div>
                    </div>
                    <div className="field">
                        <span className="field-label">{t('taxes.fields.rate_plans')}</span>
                        <div className="stack" style={{ gap: 6 }}>{options.rate_plans.map((o) => <Checkbox key={o.value} checked={rule.rate_plans.includes(String(o.value))} label={o.label} onChange={() => set('rate_plans', toggleIn(rule.rate_plans, String(o.value)))} />)}</div>
                    </div>
                </div>}
                {tab === 'advanced' && <div className="form-grid">
                    <Select fieldClass="span-12" label={t('taxes.fields.component_mode')} value={rule.component_mode} options={options.component_modes} disabled={rule.kind !== 'tax'}
                        onChange={(e) => set('component_mode', e.target.value)} error={err('component_mode')} />
                    <Input fieldClass="span-6" type="number" min={0} step="0.01" label={`${t('taxes.fields.slab')} · ${t('taxes.fields.slab_min')}`} optional value={rule.slab_min ?? ''}
                        suffix={currency()} onChange={(e) => set('slab_min', e.target.value || null)} error={err('slab_min')} />
                    <Input fieldClass="span-6" type="number" min={0} step="0.01" label={t('taxes.fields.slab_max')} optional value={rule.slab_max ?? ''}
                        suffix={currency()} onChange={(e) => set('slab_max', e.target.value || null)} error={err('slab_max')} hint={t('taxes.hints.slab')} />
                    <Input fieldClass="span-6" type="date" label={t('taxes.fields.effective_from')} value={rule.effective_from ?? ''} onChange={(e) => set('effective_from', e.target.value || null)} error={err('effective_from')} />
                    <Input fieldClass="span-6" type="date" label={t('taxes.fields.effective_to')} optional value={rule.effective_to ?? ''} onChange={(e) => set('effective_to', e.target.value || null)} error={err('effective_to')} />
                    <Input fieldClass="span-6" type="number" min={0} max={999} label={t('taxes.fields.priority')} value={rule.priority} hint={t('taxes.hints.priority')} onChange={(e) => set('priority', Number(e.target.value))} />
                    <div className="field span-12">
                        <Checkbox checked={rule.is_inclusive} onChange={(e) => set('is_inclusive', e.target.checked)} label={t('taxes.fields.inclusive')} />
                        {err('is_inclusive') && <div className="field-error">{err('is_inclusive')}</div>}
                        <Checkbox checked={rule.is_compound} onChange={(e) => set('is_compound', e.target.checked)} label={t('taxes.fields.compound')} />
                    </div>
                </div>}
            </div>
            <div className="sp-section row">
                {rule.id && <Button variant="danger-soft" icon="trash" onClick={() => setConfirmDelete(true)}>{t('ui.delete')}</Button>}
                <span className="grow" />
                <Button onClick={onClose}>{t('ui.cancel')}</Button>
                <Button variant="primary" loading={busy} onClick={save}>{t('ui.save_changes')}</Button>
            </div>
            <ConfirmDialog open={confirmDelete} danger busy={busy} title={t('ui.delete')} message={t('taxes.delete_confirm', { name: rule.name })} confirmLabel={t('ui.delete')}
                onConfirm={remove} onClose={() => setConfirmDelete(false)} />
        </SidePanel>
    );
}
