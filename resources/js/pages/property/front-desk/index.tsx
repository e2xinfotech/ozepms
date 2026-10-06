import { useState } from 'react';
import { Button, DataTable, EmptyState, Input, KpiCard, LinkButton, PageHeader, Pagination, PillTabs, toast, type Column, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { GuestCell, GuestCount, Money, reservationUrl, roomTypeLabel, StatusBadge, StayDate } from '../reservations/_components/bits';
import { ReservationDialogs, type DialogKind } from '../reservations/_components/ReservationDialogs';
import type { ReservationDetail, ReservationRow } from '../reservations/_components/types';

type Tab = 'arrivals' | 'in_house' | 'departures';
interface Props {
    list: { rows: ReservationRow[]; meta: PageMeta; counts: Record<Tab, number>; tab: Tab; today: string; rooms: { total: number; occupied: number; dirty: number; vacant: number } };
    filters: { tab?: string; q?: string };
    can: { check_in: boolean; check_out: boolean; assign: boolean; override_balance: boolean; create: boolean };
}

const ID_TYPES = ['passport', 'national_id', 'driving_licence', 'voter_id', 'other'];

/** Front desk: today's arrivals, in-house guests and departures with room assignment, check-in and check-out. */
function FrontDeskPage({ list, filters, can }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [busyId, setBusyId] = useState<string | null>(null);
    const [active, setActive] = useState<{ r: ReservationDetail; kind: DialogKind; room?: string } | null>(null);
    const tab = list.tab;

    const open = async (row: ReservationRow, kind: DialogKind, room?: string) => {
        setBusyId(row.id + kind);
        try {
            const res = await http.get<{ reservation: ReservationDetail }>(propertyApiUrl(`/reservations/${row.id}`));
            setActive({ r: res.reservation, kind, room });
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusyId(null);
        }
    };

    const columns: Column<ReservationRow>[] = [
        { key: 'ref', header: t('reservations.columns.ref'), render: (r) => <a className="id-link num" href={reservationUrl(r.id)}>{r.ref}</a> },
        { key: 'guest', header: t('reservations.columns.guest'), render: (r) => <GuestCell guest={r.guest} /> },
        {
            key: 'room', header: t('reservations.columns.room_no'), render: (r) => {
                const missing = r.rooms.filter((x) => !x.unit && !['cancelled', 'no_show', 'checked_out'].includes(x.status));
                return <span className="row">
                    {r.units.length > 0 && <span className="strong">{r.units.join(', ')}</span>}
                    {missing.length > 0 && can.assign && <Button size="sm" variant="outline" icon="door-open" loading={busyId === r.id + 'assign'} onClick={() => open(r, 'assign', missing[0].id)}>{t('reservations.actions.assign_room')}</Button>}
                    {missing.length > 0 && !can.assign && <span className="muted">{t('reservations.not_assigned')}</span>}
                </span>;
            },
        },
        { key: 'room_type', header: t('reservations.columns.room_type'), render: (r) => <span className="nowrap">{roomTypeLabel(r)}</span> },
        { key: 'check_in', header: t('reservations.columns.check_in'), render: (r) => <span className="nowrap"><StayDate value={r.check_in} late={r.late_arrival} /></span> },
        { key: 'check_out', header: t('reservations.columns.check_out'), render: (r) => <span className="nowrap"><StayDate value={r.check_out} late={r.late_departure} /></span> },
        { key: 'nights', header: t('reservations.columns.nights'), align: 'right', render: (r) => r.nights },
        { key: 'guests', header: t('reservations.columns.guests'), align: 'right', render: (r) => <GuestCount adults={r.adults} children={r.children} infants={r.infants} /> },
        { key: 'balance', header: t('reservations.columns.balance'), align: 'right', render: (r) => <Money value={r.balance} currency={r.currency} /> },
        { key: 'status', header: t('reservations.columns.status'), render: (r) => <StatusBadge size="sm" status={r.status} /> },
        {
            key: 'actions', header: t('reservations.columns.actions'), className: 'col-actions', render: (r) => (
                <span className="row nowrap">
                    {tab === 'arrivals' && can.check_in && <Button size="sm" variant="primary" icon="log-in" loading={busyId === r.id + 'check_in'} onClick={() => open(r, 'check_in')}>{t('reservations.actions.check_in')}</Button>}
                    {tab !== 'arrivals' && can.check_out && <Button size="sm" variant={tab === 'departures' ? 'primary' : 'outline'} icon="log-out" loading={busyId === r.id + 'check_out'} onClick={() => open(r, 'check_out')}>{t('reservations.actions.check_out')}</Button>}
                    {tab === 'in_house' && can.assign && <Button size="sm" variant="ghost" icon="arrow-left-right" title={t('reservations.actions.change_room')} aria-label={t('reservations.actions.change_room')} loading={busyId === r.id + 'assign'} onClick={() => open(r, 'assign')} />}
                </span>
            ),
        },
    ];

    return (
        <div className="content">
            <PageHeader title={t('reservations.front_desk.title')} description={<>{t('reservations.front_desk.description')} · {t('reservations.front_desk.today', { date: date(list.today) })}</>}
                actions={can.create && <LinkButton variant="primary" icon="plus" href={propertyUrl('/reservations/new')}>{t('reservations.new')}</LinkButton>} />

            <div className="kpi-row">
                <KpiCard icon="log-in" tone="violet" label={t('reservations.front_desk.kpis.arrivals')} value={list.counts.arrivals} active={tab === 'arrivals'} onClick={() => navigateWithQuery({ tab: null })} />
                <KpiCard icon="bed-double" tone="sky" label={t('reservations.front_desk.kpis.in_house')} value={list.counts.in_house} active={tab === 'in_house'} onClick={() => navigateWithQuery({ tab: 'in_house' })} />
                <KpiCard icon="log-out" tone="orange" label={t('reservations.front_desk.kpis.departures')} value={list.counts.departures} active={tab === 'departures'} onClick={() => navigateWithQuery({ tab: 'departures' })} />
                <KpiCard icon="door-open" tone="green" label={t('reservations.front_desk.kpis.vacant')} value={list.rooms.vacant} sub={`${list.rooms.occupied} / ${list.rooms.total}`} />
                <KpiCard icon="spray-can" tone="amber" label={t('reservations.front_desk.kpis.dirty')} value={list.rooms.dirty} />
            </div>

            <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('reservations.front_desk.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                {filters.q && <div className="field"><span className="field-label">&nbsp;</span><Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null })}>{t('ui.reset')}</Button></div>}
            </form>

            <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'arrivals' ? null : k })}
                items={(['arrivals', 'in_house', 'departures'] as Tab[]).map((k) => ({ key: k, label: t(`reservations.front_desk.tabs.${k}`), count: list.counts[k] }))} />

            <DataTable columns={columns} rows={list.rows} rowKey={(r) => r.id}
                empty={<EmptyState icon="concierge-bell" title={t(`reservations.front_desk.empty.${tab}`)} />} />
            <Pagination meta={list.meta} label={t('reservations.reservation_plural')} />

            {active && <ReservationDialogs reservation={active.r} kind={active.kind} roomId={active.room}
                idTypes={ID_TYPES.map((v) => ({ value: v, label: t(`guests.id_types.${v}`) }))}
                onClose={() => setActive(null)}
                onAssign={(roomId) => setActive({ ...active, kind: 'assign', room: roomId })}
                onDone={(r) => {
                    // After an assignment the guest can be checked in straight away.
                    if (active.kind === 'assign' && tab === 'arrivals' && r.actions.check_in) setActive({ r, kind: 'check_in' });
                    else window.location.reload();
                }} />}
        </div>
    );
}

createPage(FrontDeskPage);
