import { useState } from 'react';
import { Alert, Badge, Button, DataTable, Dropdown, EmptyState, Input, Modal, PageHeader, Pagination, RowMenu, Select, Tabs, Toggle, type Column, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';
import { act, currency, fieldError, labelOf, price } from '../_accommodation/shared';
import { splitCalc, TaxPanel, type TaxOptions } from './_components/TaxPanel';

interface Row {
    id: string; name: string; code: string; kind: 'tax' | 'service_charge' | 'fee'; tax_type: string; calc_type: string; rate: string;
    apply_to: string[]; slab_min: string | null; slab_max: string | null; component_mode: string; sac: string | null;
    is_inclusive: boolean; is_active: boolean; is_default_for_new_room_types: boolean; scoped: boolean;
}
interface Props {
    list: { rows: Row[]; meta: PageMeta; counts: Record<'all' | 'tax' | 'service_charge' | 'fee', number> };
    filters: { q?: string; status?: string; apply_to?: string; tab?: string; selected?: string };
    options: TaxOptions;
    has_own_rules: boolean;
    templates_available: boolean;
    country: string;
}

const TABS = ['all', 'tax', 'service_charge', 'fee'] as const;

/** Taxes & Fees (design: taxes-fees.png): list with an inline edit panel. */
function TaxesPage({ list, filters, options, has_own_rules, templates_available }: Props) {
    const [selected, setSelected] = useQueryState('selected', filters.selected || '');
    const [q, setQ] = useState(filters.q ?? '');
    const [rows, setRows] = useState(list.rows);
    const [calculator, setCalculator] = useState(false);
    const tab = filters.tab || 'all';

    const patch = async (row: Row, path: string, body: Record<string, unknown>, change: Partial<Row>) => {
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`/taxes/${row.id}/${path}`), body));
        if (res) setRows((l) => l.map((r) => (r.id === row.id ? { ...r, ...change } : r)));
    };
    const copyTemplates = async () => {
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl('/taxes/copy-templates')));
        if (res) window.location.reload();
    };

    const rateCell = (r: Row) => {
        const [method, basis] = splitCalc(r.calc_type);
        const slab = r.slab_min || r.slab_max
            ? (r.slab_min && r.slab_max ? t('taxes.slab_range', { min: price(r.slab_min), max: price(r.slab_max) })
                : r.slab_max ? t('taxes.slab_up_to', { max: price(r.slab_max) }) : t('taxes.slab_above', { min: price(r.slab_min) }))
            : null;
        return (
            <span className="rate-cell num">
                {method === 'percent' ? `${Number(r.rate)}%` : price(r.rate)}
                {method !== 'percent' && <small>{t(`taxes.basis_short.${basis}`)}</small>}
                {slab && <small title={t('taxes.per_room_night_slab')}>{slab}</small>}
            </span>
        );
    };

    const columns: Column<Row>[] = [
        { key: 'name', header: t('taxes.columns.name'), sortable: true, render: (r) => <span className="cell-main">{r.name}</span> },
        { key: 'code', header: t('taxes.columns.code'), sortable: true, render: (r) => r.code },
        { key: 'type', header: t('taxes.columns.type'), render: (r) => <Badge size="sm" status={r.kind}>{t(`taxes.kinds.${r.kind}`)}</Badge> },
        { key: 'rate', header: t('taxes.columns.rate'), sortable: true, render: rateCell },
        { key: 'apply_to', header: t('taxes.columns.apply_to'), render: (r) => r.apply_to.map((a) => labelOf(options.apply_to, a)).join(', ') },
        {
            key: 'calculation', header: t('taxes.columns.calculation'), render: (r) => {
                const [method, basis] = splitCalc(r.calc_type);
                return <span title={r.component_mode === 'gst_split' ? labelOf(options.component_modes, 'gst_split') : undefined}>
                    {method === 'percent' ? t('taxes.methods.percent') : t(`taxes.bases.${basis}`)}{r.component_mode === 'gst_split' ? ' · CGST/SGST' : ''}
                </span>;
            },
        },
        {
            key: 'status', header: t('taxes.columns.status'), sortable: true, render: (r) => (
                <span onClick={(e) => e.stopPropagation()}>
                    <Toggle checked={r.is_active} label={t(`ui.status.${r.is_active ? 'active' : 'inactive'}`)}
                        onChange={(v) => patch(r, 'status', { is_active: v }, { is_active: v })} />
                </span>
            ),
        },
        {
            key: 'default', header: t('taxes.columns.default'), align: 'center', render: (r) => (
                <span className="radio-cell" onClick={(e) => e.stopPropagation()}>
                    <input type="radio" checked={r.is_default_for_new_room_types} title={t('taxes.fields.default_new')} aria-label={t('taxes.fields.default_new')}
                        onChange={() => undefined} onClick={() => patch(r, 'default', { default: !r.is_default_for_new_room_types }, { is_default_for_new_room_types: !r.is_default_for_new_room_types })} />
                </span>
            ),
        },
        {
            key: 'actions', header: t('taxes.columns.actions'), className: 'col-actions', render: (r) => (
                <RowMenu items={[{ label: t('ui.edit'), icon: 'pencil', onClick: () => setSelected(r.id) }]} />
            ),
        },
    ];

    const hasFilters = !!(filters.q || filters.status || filters.apply_to);

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('taxes.title')} description={t('taxes.description')} actions={<>
                    <Dropdown trigger={(toggle) => <Button variant="outline" icon="settings" onClick={toggle}>{t('taxes.settings')}</Button>} items={[
                        { label: t('taxes.calculator.title'), icon: 'calculator', onClick: () => setCalculator(true) },
                        ...(templates_available ? [{ label: t('taxes.copy_templates'), icon: 'copy', onClick: copyTemplates }] : []),
                    ]} />
                    <Button variant="primary" icon="plus" onClick={() => setSelected('new')}>{t('taxes.add')}</Button>
                </>} />

                {!has_own_rules && templates_available && (
                    <Alert tone="info">
                        <div className="row-between"><span>{t('taxes.templates_banner')}</span><Button size="sm" variant="primary" onClick={copyTemplates}>{t('taxes.copy_templates')}</Button></div>
                    </Alert>
                )}

                <div className="row-between" style={{ alignItems: 'flex-end', flexWrap: 'wrap' }}>
                    <Tabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'all' ? null : k })}
                        items={TABS.map((k) => ({ key: k, label: t(`taxes.tabs.${k}`), count: list.counts[k] }))} />
                    <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                        <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('taxes.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                        <Select label={t('taxes.columns.status')} value={filters.status ?? ''} placeholder={t('taxes.all_statuses')}
                            options={['active', 'inactive'].map((s) => ({ value: s, label: t(`ui.status.${s}`) }))} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                        <Select label={t('taxes.columns.apply_to')} value={filters.apply_to ?? ''} placeholder={t('ui.all')} options={options.apply_to} onChange={(e) => navigateWithQuery({ apply_to: e.target.value })} />
                        {hasFilters && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, status: null, apply_to: null })}>{t('ui.reset')}</Button>}
                    </form>
                </div>

                <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                    empty={!hasFilters && list.counts.all === 0 ? <EmptyState icon="receipt" title={t('taxes.no_rules')} text={t('taxes.no_rules_hint')} /> : undefined} />
                <Pagination meta={list.meta} label={t('taxes.item_plural')} />
            </div>

            {selected && <TaxPanel id={selected} options={options} onClose={() => setSelected(null)}
                onSaved={(id) => navigateWithQuery({ selected: id }, false)} />}
            {calculator && <TaxCalculator onClose={() => setCalculator(false)} />}
        </div>
    );
}

interface Preview { message?: string; currency: string; taxable: string; tax_total: string; total: string; components: { component: string; name: string; rate: string; amount: string; calc_type: string }[] }

/** Shows which taxes the current rules put on one room charge (checks GST slabs quickly). */
function TaxCalculator({ onClose }: { onClose: () => void }) {
    const [form, setForm] = useState({ tariff: '4500', nights: '1', persons: '2', guest_state: '' });
    const [result, setResult] = useState<Preview | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const run = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.post<Preview>(propertyApiUrl('/taxes/preview'), {
            tariff: form.tariff, nights: Number(form.nights), persons: Number(form.persons), guest_state: form.guest_state || null,
        }), setError);
        setBusy(false);
        if (res) setResult(res);
    };

    return (
        <Modal open title={t('taxes.calculator.title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.close')}</Button>
            <Button variant="primary" icon="calculator" loading={busy} onClick={run}>{t('taxes.calculator.calculate')}</Button>
        </>}>
            <div className="stack">
                <p className="muted">{t('taxes.calculator.intro')}</p>
                <div className="form-grid">
                    <Input fieldClass="span-6" type="number" min={0} step="0.01" label={t('taxes.calculator.tariff')} suffix={currency()} value={form.tariff}
                        onChange={(e) => setForm({ ...form, tariff: e.target.value })} error={fieldError(error, 'tariff')} />
                    <Input fieldClass="span-3" type="number" min={1} label={t('taxes.calculator.nights')} value={form.nights} onChange={(e) => setForm({ ...form, nights: e.target.value })} error={fieldError(error, 'nights')} />
                    <Input fieldClass="span-3" type="number" min={1} label={t('taxes.calculator.persons')} value={form.persons} onChange={(e) => setForm({ ...form, persons: e.target.value })} />
                    <Input fieldClass="span-6" label={t('taxes.calculator.guest_state')} optional value={form.guest_state} placeholder="IN-KA" maxLength={10}
                        onChange={(e) => setForm({ ...form, guest_state: e.target.value.toUpperCase() })} />
                </div>
                {result && <div className="calc-result">
                    <table>
                        <tbody>
                            <tr><td>{t('taxes.calculator.taxable')}</td><td className="right num">{price(result.taxable)}</td></tr>
                            {result.components.length === 0 && <tr><td colSpan={2} className="muted">{t('taxes.calculator.none')}</td></tr>}
                            {result.components.map((c, i) => (
                                <tr key={i}><td>{c.name}{c.calc_type === 'percent' ? ` (${Number(c.rate)}%)` : ''}</td><td className="right num">{price(c.amount)}</td></tr>
                            ))}
                            <tr><td>{t('taxes.calculator.total')}</td><td className="right num">{price(result.total)}</td></tr>
                        </tbody>
                    </table>
                </div>}
            </div>
        </Modal>
    );
}

createPage(TaxesPage);
