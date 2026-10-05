import { useState } from 'react';
import { Badge, DataTable, Flag, Icon, Input, KpiCard, Pagination, PillTabs, RowMenu, Select, type Column, type MenuEntry, type Option, type PageMeta } from '@/components/ui';
import { number } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { PropertyKpis, PropertyRow } from './types';

export interface PropertyListProps {
    rows: PropertyRow[];
    meta: PageMeta;
    counts: Record<string, number>;
    kpis: PropertyKpis;
    filters: { q: string; country: string; type: string; status: string };
    options: { countries: Option[]; types: (Option & { code: string })[]; statuses: string[] };
}

/** KPI tiles of the property lists. */
export function PropertyKpiRow({ kpis, withRooms }: { kpis: PropertyKpis; withRooms?: boolean }) {
    const pct = (n: number) => (kpis.total > 0 ? Math.round((n * 1000) / kpis.total) / 10 : 0);
    return (
        <div className="kpi-row">
            <KpiCard icon="building-2" tone="blue" label={t('property.kpi.total')} value={number(kpis.total)} sub={t('property.kpi.across_countries', { n: kpis.countries })} />
            {withRooms && <KpiCard icon="bed-double" tone="sky" label={t('property.kpi.total_rooms')} value={number(kpis.rooms)} sub={t('admin.kpi.all_properties')} />}
            <KpiCard icon="check-circle" tone="green" label={t('property.kpi.active')} value={number(kpis.active)} sub={t('property.kpi.of_total', { p: pct(kpis.active) })} />
            <KpiCard icon="pause" tone="slate" label={t('property.kpi.inactive')} value={number(kpis.inactive)} sub={t('property.kpi.of_total', { p: pct(kpis.inactive) })} />
            <KpiCard icon="settings" tone="orange" label={t('property.kpi.setup')} value={number(kpis.setup)} sub={t('property.kpi.of_total', { p: pct(kpis.setup) })} />
        </div>
    );
}

/** Search + country / type / status filters, driven by the query string. */
export function PropertyFilters({ filters, options }: Pick<PropertyListProps, 'filters' | 'options'>) {
    const [q, setQ] = useState(filters.q);
    return (
        <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
            <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('property.search_placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
            <Select label={t('property.country')} value={filters.country} options={options.countries} placeholder={t('property.all_countries')} onChange={(e) => navigateWithQuery({ country: e.target.value })} />
            <Select label={t('property.type')} value={filters.type} options={options.types.map((o) => ({ value: o.code, label: o.label }))} placeholder={t('property.all_types')} onChange={(e) => navigateWithQuery({ type: e.target.value })} />
            <Select label={t('ui.status_label')} value={filters.status} options={options.statuses.map((s) => ({ value: s, label: t(`ui.status.${s}`) }))} placeholder={t('property.all_statuses')} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
            {(filters.q || filters.country || filters.type || filters.status) && (
                <button type="button" className="btn btn-ghost" onClick={() => navigateWithQuery({ q: null, country: null, type: null, status: null })}><Icon name="x" size={16} />{t('ui.reset')}</button>
            )}
        </form>
    );
}

export function PropertyStatusTabs({ counts, active, statuses }: { counts: Record<string, number>; active: string; statuses: string[] }) {
    return (
        <PillTabs active={active || 'all'} onChange={(key) => navigateWithQuery({ status: key === 'all' ? null : key })}
            items={[{ key: 'all', label: t('ui.all'), count: counts.all ?? 0 }, ...statuses.map((s) => ({ key: s, label: t(`ui.status.${s}`), count: counts[s] ?? 0 }))]} />
    );
}

/** Property table; `extra` adds columns before Status, `menu` builds the row "⋮" menu. */
export function PropertyTable({ rows, meta, selected, onSelect, extra = [], menu }: {
    rows: PropertyRow[]; meta: PageMeta; selected: string | null; onSelect: (row: PropertyRow) => void;
    extra?: Column<PropertyRow>[]; menu: (row: PropertyRow) => MenuEntry[];
}) {
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const columns: Column<PropertyRow>[] = [
        {
            key: 'name', header: t('property.property'), sortable: true, width: 280, render: (r) => (
                <div className="media-cell">
                    {r.image ? <img src={r.image} alt="" style={{ width: 72, height: 48 }} /> : <span className="thumb" style={{ width: 72, height: 48 }}><Icon name="hotel" size={20} /></span>}
                    <div><div className="cell-main">{r.name}</div>{r.tagline && <div className="cell-sub">{r.tagline}</div>}</div>
                </div>
            ),
        },
        { key: 'code', header: t('property.code_short'), sortable: true, render: (r) => <span className="num">{r.code}</span> },
        { key: 'location', header: t('property.location'), width: 170, render: (r) => <span className="row" style={{ gap: 10 }}><Flag code={r.country_code} />{r.location || '—'}</span> },
        { key: 'type', header: t('property.type_short'), render: (r) => r.type_label ?? '—' },
        { key: 'rooms', header: t('property.total_rooms'), sortable: true, align: 'right', render: (r) => number(r.rooms) },
        ...extra,
        { key: 'status', header: t('ui.status_label'), sortable: true, render: (r) => <Badge status={r.status} /> },
        { key: 'actions', header: t('ui.actions'), className: 'col-actions', render: (r) => <RowMenu items={menu(r)} /> },
    ];
    return (
        <>
            <DataTable columns={columns} rows={rows} rowKey={(r) => r.code} onRowClick={onSelect} selectedKey={selected}
                selectable selected={checked} onSelect={setChecked} />
            <Pagination meta={meta} label={t('property.properties_lc')} />
        </>
    );
}
