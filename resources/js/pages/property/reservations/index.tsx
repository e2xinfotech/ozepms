import { useState } from 'react';
import { Button, DataTable, DateRange, Dropdown, EmptyState, Input, KpiCard, LinkButton, PageHeader, Pagination, PillTabs, RowMenu, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';
import { GuestCell, GuestCount, Money, reservationUrl, roomTypeLabel, sourceLabel, StatusBadge, StayDate, unitsLabel } from './_components/bits';
import { ReservationPanel } from './_components/ReservationPanel';
import type { ReservationRow } from './_components/types';

type CountKey = 'all' | 'confirmed' | 'in_house' | 'pending' | 'arrivals' | 'departures' | 'cancelled' | 'no_show' | 'group' | 'checked_out';

interface Props {
    list: { rows: ReservationRow[]; meta: PageMeta; counts: Record<CountKey, number> };
    filters: Partial<Record<'q' | 'status' | 'source' | 'from' | 'to' | 'date_field' | 'room_type' | 'rate_plan' | 'unit' | 'payment' | 'tab' | 'guest' | 'selected', string>>;
    options: { sources: Option[]; room_types: Option[]; rate_plans: Option[] };
    can: { create: boolean; update: boolean };
}

const KPIS: { key: CountKey; icon: string; tone: 'blue' | 'green' | 'sky' | 'violet' | 'orange' | 'amber' | 'red' }[] = [
    { key: 'all', icon: 'calendar-check', tone: 'blue' },
    { key: 'confirmed', icon: 'check-circle', tone: 'green' },
    { key: 'in_house', icon: 'bed-double', tone: 'sky' },
    { key: 'arrivals', icon: 'log-in', tone: 'violet' },
    { key: 'departures', icon: 'log-out', tone: 'orange' },
    { key: 'pending', icon: 'clock', tone: 'amber' },
    { key: 'cancelled', icon: 'circle-x', tone: 'red' },
];
const TABS: CountKey[] = ['all', 'confirmed', 'in_house', 'pending', 'arrivals', 'departures', 'cancelled', 'no_show', 'group'];
const STATUSES = ['inquiry', 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'];

/** Reservations list v2 with KPI chips, filters, tabs and the details panel (design reservations-list-panel.png). */
function ReservationsPage({ list, filters, options, can }: Props) {
    const [selected, setSelected] = useQueryState('selected', filters.selected ?? '');
    const [q, setQ] = useState(filters.q ?? '');
    const [more, setMore] = useState(!!(filters.room_type || filters.rate_plan || filters.payment));
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const tab = (filters.tab as CountKey) || 'all';
    const exportUrl = propertyApiUrl('/reservations/export') + window.location.search;

    const columns: Column<ReservationRow>[] = [
        { key: 'ref', header: t('reservations.columns.ref'), sortable: true, render: (r) => <a className="id-link num" href={reservationUrl(r.id)} onClick={(e) => e.stopPropagation()}>{r.ref}</a> },
        { key: 'guest', header: t('reservations.columns.guest'), sortable: true, render: (r) => <GuestCell guest={r.guest} /> },
        { key: 'room_no', header: t('reservations.columns.room_no'), render: (r) => <span className="strong" title={unitsLabel(r)}>{r.units[0] ?? '—'}{r.units.length > 1 ? ` +${r.units.length - 1}` : ''}</span> },
        { key: 'room_type', header: t('reservations.columns.room_type'), className: 'hide-with-panel', render: (r) => <span className="nowrap">{roomTypeLabel(r)}</span> },
        { key: 'check_in', header: t('reservations.columns.check_in'), sortable: true, render: (r) => <span className="nowrap"><StayDate value={r.check_in} late={r.late_arrival} /></span> },
        { key: 'check_out', header: t('reservations.columns.check_out'), sortable: true, render: (r) => <span className="nowrap"><StayDate value={r.check_out} late={r.late_departure} /></span> },
        { key: 'nights', header: t('reservations.columns.nights'), sortable: true, align: 'right', render: (r) => r.nights },
        { key: 'guests', header: t('reservations.columns.guests'), align: 'right', render: (r) => <GuestCount adults={r.adults} children={r.children} infants={r.infants} /> },
        { key: 'total', header: t('reservations.columns.total'), sortable: true, align: 'right', render: (r) => <Money value={r.total} currency={r.currency} /> },
        { key: 'status', header: t('reservations.columns.status'), sortable: true, render: (r) => <StatusBadge size="sm" status={r.room_count > 1 && r.status === 'confirmed' ? 'confirmed' : r.status} /> },
        { key: 'source', header: t('reservations.columns.source'), className: 'hide-with-panel', render: (r) => sourceLabel(r) },
        {
            key: 'actions', header: t('reservations.columns.actions'), className: 'col-actions', render: (r) => (
                <RowMenu items={[
                    { label: t('ui.view'), icon: 'eye', onClick: () => setSelected(r.id) },
                    { label: t('reservations.actions.open'), icon: 'file-text', href: reservationUrl(r.id) },
                    ...(can.update && ['inquiry', 'pending', 'confirmed', 'checked_in'].includes(r.status) ? [{ label: t('ui.edit'), icon: 'pencil', href: propertyUrl(`/reservations/${r.id}/edit`) }] : []),
                ]} />
            ),
        },
    ];

    const hasFilters = !!(filters.q || filters.status || filters.source || filters.from || filters.to || filters.room_type || filters.rate_plan || filters.payment || filters.guest);
    const reset = () => navigateWithQuery({ q: null, status: null, source: null, from: null, to: null, date_field: null, room_type: null, rate_plan: null, payment: null, guest: null, unit: null });

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('reservations.title')} description={t('reservations.description')} actions={
                    can.create && <LinkButton variant="primary" icon="plus" href={propertyUrl('/reservations/new')}>{t('reservations.new')}</LinkButton>
                } />

                <div className="kpi-chips" role="tablist">
                    {KPIS.map((k) => (
                        <KpiCard key={k.key} compact icon={k.icon} tone={k.tone} label={t(`reservations.kpis.${k.key}`)} value={list.counts[k.key]}
                            active={tab === k.key} onClick={() => navigateWithQuery({ tab: k.key === 'all' ? null : k.key })} />
                    ))}
                </div>

                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('reservations.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('reservations.columns.status')} value={filters.status ?? ''} placeholder={t('reservations.all_statuses')}
                        options={STATUSES.map((s) => ({ value: s, label: t(`reservations.status.${s}`) }))} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                    <Select label={t('reservations.columns.source')} value={filters.source ?? ''} placeholder={t('reservations.all_sources')} options={options.sources}
                        onChange={(e) => navigateWithQuery({ source: e.target.value })} />
                    <div className="field">
                        <span className="field-label">{t(`reservations.date_fields.${filters.date_field || 'stay'}`)}</span>
                        <DateRange from={filters.from ?? ''} to={filters.to ?? ''} maxDays={366} onApply={(from, to) => navigateWithQuery({ from, to })} />
                    </div>
                    <div className="field filter-buttons">
                        <span className="field-label">&nbsp;</span>
                        <div className="row">
                            <Button icon="filter" variant={more ? 'outline' : 'secondary'} onClick={() => setMore((m) => !m)}>{t('reservations.more_filters')}</Button>
                            <Dropdown items={[{ label: t('reservations.export_csv'), icon: 'download', href: exportUrl }]}
                                trigger={(toggle) => <Button iconRight="chevron-down" onClick={toggle}>{t('reservations.export')}</Button>} />
                            {hasFilters && <Button variant="ghost" icon="x" onClick={reset}>{t('ui.reset')}</Button>}
                        </div>
                    </div>
                </form>
                {more && <div className="filter-bar">
                    <Select label={t('reservations.date_range')} value={filters.date_field || 'stay'} options={['stay', 'check_in', 'check_out', 'created'].map((v) => ({ value: v, label: t(`reservations.date_fields.${v}`) }))}
                        onChange={(e) => navigateWithQuery({ date_field: e.target.value === 'stay' ? null : e.target.value })} />
                    <Select label={t('reservations.fields.room_type')} value={filters.room_type ?? ''} placeholder={t('reservations.all_room_types')} options={options.room_types}
                        onChange={(e) => navigateWithQuery({ room_type: e.target.value })} />
                    <Select label={t('reservations.fields.rate_plan')} value={filters.rate_plan ?? ''} placeholder={t('reservations.all_rate_plans')} options={options.rate_plans}
                        onChange={(e) => navigateWithQuery({ rate_plan: e.target.value })} />
                    <Select label={t('reservations.columns.payment')} value={filters.payment ?? ''} placeholder={t('reservations.all_payments')}
                        options={['unpaid', 'partial', 'paid', 'refunded'].map((v) => ({ value: v, label: t(`reservations.payment_status.${v}`) }))} onChange={(e) => navigateWithQuery({ payment: e.target.value })} />
                </div>}

                <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'all' ? null : k })}
                    items={TABS.map((k) => ({ key: k, label: t(`reservations.tabs.${k}`), count: list.counts[k] }))} />

                <div className="res-table">
                    <DataTable columns={columns} rows={list.rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                        selectable selected={checked} onSelect={setChecked}
                        empty={!hasFilters && list.counts.all === 0
                            ? <EmptyState icon="calendar-check" title={t('reservations.no_reservations')} text={t('reservations.no_reservations_hint')}
                                action={can.create ? <LinkButton variant="primary" icon="plus" href={propertyUrl('/reservations/new')}>{t('reservations.new')}</LinkButton> : undefined} />
                            : undefined} />
                </div>
                <Pagination meta={list.meta} label={t('reservations.reservation_plural')} />
            </div>
            {selected && <ReservationPanel id={selected} onClose={() => setSelected(null)} />}
        </div>
    );
}

createPage(ReservationsPage);
