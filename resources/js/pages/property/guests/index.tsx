import { useState } from 'react';
import { Badge, Button, DataTable, DateRange, EmptyState, Flag, Input, LinkButton, PageHeader, Pagination, PillTabs, RowMenu, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';
import { GuestDrawer } from './_components/GuestDrawer';
import { GuestPanel } from './_components/GuestPanel';
import type { GuestDetail, GuestRow } from './_components/types';

type Tab = 'all' | 'in_house' | 'upcoming' | 'past';
interface Props {
    list: { rows: GuestRow[]; meta: PageMeta; counts: Record<Tab, number> };
    filters: Partial<Record<'q' | 'nationality' | 'type' | 'vip' | 'from' | 'to' | 'tab' | 'selected', string>>;
    options: { countries: Option[]; guest_types: Option[]; titles: Option[]; id_types: Option[] };
    can: { update: boolean; reserve: boolean; reservations: boolean; folio: boolean };
}

/** Guests list + profile panel (design guests.png). */
function GuestsPage({ list, filters, options, can }: Props) {
    const [selected, setSelected] = useQueryState('selected', filters.selected ?? list.rows[0]?.id ?? '');
    const [q, setQ] = useState(filters.q ?? '');
    const [drawer, setDrawer] = useState<{ guest: GuestDetail | null } | null>(null);
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const [panelKey, setPanelKey] = useState(0);
    const tab = (filters.tab as Tab) || 'all';
    const hasFilters = !!(filters.q || filters.nationality || filters.type || filters.vip || filters.from || filters.to);

    const columns: Column<GuestRow>[] = [
        { key: 'number', header: t('guests.columns.id'), sortable: true, render: (g) => <span className="id-link num">{g.number}</span> },
        { key: 'name', header: t('guests.columns.name'), sortable: true, render: (g) => <span className="row nowrap"><Flag code={g.nationality} /><span className="cell-main">{g.name}</span>{g.vip && <Badge size="sm" tone="amber">VIP</Badge>}</span> },
        { key: 'contact', header: t('guests.columns.contact'), className: 'hide-with-panel', render: (g) => <div className="text-sm"><div>{g.phone ?? '—'}</div><div className="cell-sub">{g.email ?? ''}</div></div> },
        { key: 'nationality', header: t('guests.columns.nationality'), sortable: true, className: 'hide-with-panel', render: (g) => options.countries.find((c) => c.value === g.nationality)?.label ?? g.nationality ?? '—' },
        { key: 'id_doc', header: t('guests.columns.id_doc'), className: 'hide-with-panel', render: (g) => g.id_type ? <div className="text-sm"><div>{t(`guests.id_types.${g.id_type}`)}</div><div className="cell-sub num">{g.id_number ?? ''}</div></div> : '—' },
        { key: 'stays', header: t('guests.columns.stays'), align: 'right', className: 'hide-with-panel', render: (g) => g.stays },
        { key: 'last_stay', header: t('guests.columns.last_stay'), render: (g) => <span className="nowrap">{g.last_stay ? date(g.last_stay) : '—'}</span> },
        { key: 'status', header: t('guests.columns.status'), render: (g) => <Badge size="sm" status={g.status === 'new' ? 'inactive' : g.status === 'past' ? 'checked_out' : g.status}>{t(`guests.status.${g.status}`)}</Badge> },
        {
            key: 'actions', header: t('guests.columns.actions'), className: 'col-actions', render: (g) => (
                <span className="row nowrap" onClick={(e) => e.stopPropagation()}>
                    <Button size="sm" variant="outline" onClick={() => setSelected(g.id)}>{t('ui.view')}</Button>
                    <RowMenu items={[
                        ...(can.reserve ? [{ label: t('guests.quick.new_reservation'), icon: 'plus', href: propertyUrl(`/reservations/new?guest=${g.id}`) }] : []),
                        ...(can.reservations ? [{ label: t('guests.view_all_stays'), icon: 'calendar-check', href: propertyUrl(`/reservations?guest=${g.id}`) }] : []),
                    ]} />
                </span>
            ),
        },
    ];

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('guests.title')} description={t('guests.description')} actions={<>
                    <LinkButton icon="download" href={propertyApiUrl('/guests/export')}>{t('guests.export')}</LinkButton>
                    {can.update && <Button variant="primary" icon="plus" onClick={() => setDrawer({ guest: null })}>{t('guests.add_guest')}</Button>}
                </>} />

                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('guests.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('guests.fields.nationality_iso2')} value={filters.nationality ?? ''} placeholder={t('guests.all_nationalities')} options={options.countries}
                        onChange={(e) => navigateWithQuery({ nationality: e.target.value })} />
                    <Select label={t('guests.fields.guest_type')} value={filters.type ?? ''} placeholder={t('guests.all_types')} options={options.guest_types}
                        onChange={(e) => navigateWithQuery({ type: e.target.value })} />
                    <Select label={t('guests.columns.status')} value={filters.vip ?? ''} placeholder={t('guests.all_statuses')} options={[{ value: '1', label: t('guests.vip_only') }]}
                        onChange={(e) => navigateWithQuery({ vip: e.target.value })} />
                    <div className="field">
                        <span className="field-label">{t('guests.created_date')}</span>
                        <DateRange from={filters.from ?? ''} to={filters.to ?? ''} maxDays={3660} onApply={(from, to) => navigateWithQuery({ from, to })} />
                    </div>
                    {hasFilters && <div className="field"><span className="field-label">&nbsp;</span><Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, nationality: null, type: null, vip: null, from: null, to: null })}>{t('ui.reset')}</Button></div>}
                </form>

                <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'all' ? null : k })}
                    items={(['all', 'in_house', 'upcoming', 'past'] as Tab[]).map((k) => ({ key: k, label: t(`guests.tabs.${k}`), count: list.counts[k] }))} />

                <DataTable columns={columns} rows={list.rows} rowKey={(g) => g.id} onRowClick={(g) => setSelected(g.id)} selectedKey={selected}
                    selectable selected={checked} onSelect={setChecked}
                    empty={!hasFilters && list.counts.all === 0 ? <EmptyState icon="users" title={t('guests.no_guests')} text={t('guests.no_guests_hint')} /> : undefined} />
                <Pagination meta={list.meta} label={t('guests.guest_plural')} />
            </div>
            {selected && <GuestPanel key={`${selected}-${panelKey}`} id={selected} can={can} onClose={() => setSelected(null)} onEdit={(g) => setDrawer({ guest: g })} />}
            {drawer && <GuestDrawer guest={drawer.guest} options={options} onClose={() => setDrawer(null)}
                onSaved={(id) => {
                    setDrawer(null);
                    if (drawer.guest) setPanelKey((k) => k + 1);
                    else window.location.href = propertyUrl(`/guests?selected=${id}`);
                }} />}
        </div>
    );
}

createPage(GuestsPage);
