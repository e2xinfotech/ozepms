import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, Icon, Input, KpiCard, LinkButton, PageHeader, Pagination, RowMenu, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { number } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';
import { currency } from '../_accommodation/shared';
import { ChannelIcons, PolicyBadge } from './_components/RatePlanBits';
import { RatePlanPanel } from './_components/RatePlanPanel';
import type { RatePlanRow } from './_components/types';

interface Props {
    list: { rows: RatePlanRow[]; meta: PageMeta; counts: Record<'all' | 'active' | 'inactive' | 'mapped' | 'unmapped', number> };
    filters: { q?: string; room_type?: string; meal_plan?: string; status?: string; tab?: string; selected?: string };
    options: { room_types: Option[]; meal_plans: Option[] };
    can: { create: boolean; update: boolean };
}

const KPIS: { key: 'all' | 'active' | 'inactive' | 'mapped' | 'unmapped'; icon: string; tone: 'blue' | 'green' | 'slate' | 'violet' | 'orange' }[] = [
    { key: 'all', icon: 'calendar-days', tone: 'blue' },
    { key: 'active', icon: 'check-circle', tone: 'green' },
    { key: 'inactive', icon: 'pause', tone: 'slate' },
    { key: 'mapped', icon: 'network', tone: 'violet' },
    { key: 'unmapped', icon: 'x', tone: 'orange' },
];

/** Rate plans v2 (design: rate-plans-v2.png). */
function RatePlansPage({ list, filters, options, can }: Props) {
    const [selected, setSelected] = useQueryState('selected', filters.selected || list.rows[0]?.id || '');
    const [q, setQ] = useState(filters.q ?? '');
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const tab = filters.tab || 'all';
    const cur = currency();

    const columns: Column<RatePlanRow>[] = [
        {
            key: 'name', header: t('rates.columns.name'), sortable: true, render: (r) => (
                <span className="cell-main">{r.name}{r.is_default && <> <Badge size="sm" tone="blue">{t('rates.default_badge')}</Badge></>}</span>
            ),
        },
        { key: 'code', header: t('rates.columns.code'), sortable: true, render: (r) => r.code },
        { key: 'meal_plan', header: t('rates.columns.meal_plan'), render: (r) => <span className="row" title={r.meal_plan?.label}><Icon name="utensils" size={16} />{r.meal_plan?.label ?? '—'}</span> },
        { key: 'room_types', header: t('rates.columns.room_types'), render: (r) => r.all_room_types ? t('rates.all_room_types') : (r.room_types.join(', ') || <span className="muted">{t('rates.none_linked')}</span>) },
        { key: 'policy', header: t('rates.columns.policy'), render: (r) => <span title={r.policy?.name}><PolicyBadge refundable={r.policy?.refundable} /></span> },
        { key: 'base_rate', header: t('rates.columns.base_rate', { currency: cur }), align: 'right', render: (r) => r.base_rate ? number(Number(r.base_rate), 2) : '—' },
        { key: 'min_los', header: t('rates.columns.min_los'), sortable: true, align: 'right', render: (r) => r.min_los },
        { key: 'channels', header: t('rates.columns.channels'), render: (r) => <ChannelIcons channels={r.channels} /> },
        { key: 'status', header: t('rates.columns.status'), sortable: true, render: (r) => <Badge size="sm" status={r.is_active ? 'active' : 'inactive'} /> },
        {
            key: 'actions', header: t('rates.columns.actions'), className: 'col-actions', render: (r) => (
                <RowMenu items={[
                    { label: t('ui.view'), icon: 'eye', onClick: () => setSelected(r.id) },
                    ...(can.update ? [{ label: t('ui.edit'), icon: 'pencil', href: propertyUrl(`/rate-plans/${r.id}/edit`) }] : []),
                ]} />
            ),
        },
    ];

    const hasFilters = !!(filters.q || filters.room_type || filters.meal_plan || filters.status);

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('rates.title')} description={t('rates.description')} actions={
                    can.create && <LinkButton variant="primary" icon="plus" href={propertyUrl('/rate-plans/new')}>{t('rates.add_rate_plan')}</LinkButton>
                } />

                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('rates.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('rates.columns.room_types')} value={filters.room_type ?? ''} placeholder={t('rates.all_room_types')} options={options.room_types} onChange={(e) => navigateWithQuery({ room_type: e.target.value })} />
                    <Select label={t('rates.columns.meal_plan')} value={filters.meal_plan ?? ''} placeholder={t('rates.all_meal_plans')} options={options.meal_plans} onChange={(e) => navigateWithQuery({ meal_plan: e.target.value })} />
                    <Select label={t('rates.columns.status')} value={filters.status ?? ''} placeholder={t('rates.all_statuses')}
                        options={['active', 'inactive'].map((s) => ({ value: s, label: t(`ui.status.${s}`) }))} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                    {hasFilters && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, room_type: null, meal_plan: null, status: null })}>{t('ui.reset')}</Button>}
                </form>

                <div className="kpi-tabs" role="tablist">
                    {KPIS.map((k) => (
                        <KpiCard key={k.key} compact icon={k.icon} tone={k.tone} label={t(`rates.kpis.${k.key}`)} value={list.counts[k.key]}
                            active={tab === k.key} onClick={() => navigateWithQuery({ tab: k.key === 'all' ? null : k.key })} />
                    ))}
                </div>

                <DataTable columns={columns} rows={list.rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                    selectable selected={checked} onSelect={setChecked}
                    empty={!hasFilters && list.counts.all === 0
                        ? <EmptyState icon="tags" title={t('rates.no_rate_plans')} text={t('rates.no_rate_plans_hint')} />
                        : undefined} />
                <Pagination meta={list.meta} label={t('rates.rate_plan_plural')} />
            </div>
            {selected && <RatePlanPanel id={selected} canUpdate={can.update} canCreate={can.create} onClose={() => setSelected(null)} />}
        </div>
    );
}

createPage(RatePlansPage);
