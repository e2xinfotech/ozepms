import { useCallback, useEffect, useState } from 'react';
import { ComposeEmailDialog } from '@/components/property/ComposeEmailDialog';
import { Badge, Button, Dropdown, EmptyState, Flag, KeyValue, LinkButton, SidePanel, Tabs, type MenuEntry } from '@/components/ui';
import { date } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { BillingSlot } from './BillingSlot';
import { GuestCount, Money, reservationUrl, StatusBadge } from './bits';
import { HistoryList, NotesBox, useHistory } from './History';
import { ReservationDialogs, type DialogKind } from './ReservationDialogs';
import { billingRef, type ReservationDetail } from './types';

/** "Reservation Details" side panel of the reservations list (design reservations-list-panel.png). */
export function ReservationPanel({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged?: () => void }) {
    const [r, setR] = useState<ReservationDetail | null>(null);
    const [failed, setFailed] = useState<ApiError | null>(null);
    const [tab, setTab] = useState('overview');
    const [emailOpen, setEmailOpen] = useState(false);
    const [dialog, setDialog] = useState<{ kind: DialogKind; room?: string } | null>(null);

    const load = useCallback(() => {
        let alive = true;
        setFailed(null);
        http.get<{ reservation: ReservationDetail }>(propertyApiUrl(`/reservations/${id}`))
            .then((res) => alive && setR(res.reservation))
            .catch((e: ApiError) => alive && setFailed(e));
        return () => { alive = false; };
    }, [id]);
    useEffect(() => load(), [load]);

    if (failed) {
        return <SidePanel title={t('reservations.details_title')} onClose={onClose}>
            <EmptyState icon="circle-alert" title={t('reservations.load_failed')} text={failed.ref ? `${t('errors.reference')}: ${failed.ref}` : failed.message}
                action={<Button icon="refresh" onClick={load}>{t('reservations.retry')}</Button>} />
        </SidePanel>;
    }
    if (!r || r.id !== id) {
        return <SidePanel title={t('reservations.details_title')} onClose={onClose}>
            <div className="sp-section stack" style={{ borderTop: 0 }}><div className="skeleton" style={{ height: 48 }} /><div className="skeleton" style={{ height: 120 }} /><div className="skeleton" style={{ height: 200 }} /></div>
        </SidePanel>;
    }

    const room = r.rooms.find((x) => !['cancelled', 'no_show'].includes(x.status)) ?? r.rooms[0];
    const changed = (next: ReservationDetail) => { setR(next); onChanged?.(); };
    const more: MenuEntry[] = [
        ...(r.actions.confirm ? [{ label: t('reservations.actions.confirm'), icon: 'check', onClick: () => setDialog({ kind: 'confirm' }) }] : []),
        ...(r.actions.assign ? [{ label: t('reservations.actions.assign_room'), icon: 'door-open', onClick: () => setDialog({ kind: 'assign' }) }] : []),
        ...(r.actions.check_in ? [{ label: t('reservations.actions.check_in'), icon: 'log-in', onClick: () => setDialog({ kind: 'check_in' }) }] : []),
        ...(r.actions.check_out ? [{ label: t('reservations.actions.check_out'), icon: 'log-out', onClick: () => setDialog({ kind: 'check_out' }) }] : []),
        ...(r.actions.no_show ? [{ label: t('reservations.actions.no_show'), icon: 'ban', danger: true, onClick: () => setDialog({ kind: 'no_show' }) }] : []),
        { label: t('reservations.actions.open'), icon: 'eye', href: reservationUrl(r.id) },
    ];

    return (
        <SidePanel title={t('reservations.details_title')} onClose={onClose}>
            <div className="sp-section row-between" style={{ borderTop: 0, paddingTop: 0 }}>
                <div className="row" style={{ flexWrap: 'wrap' }}>
                    <h3 style={{ fontSize: 20, margin: 0 }}>{r.guest.name}</h3>
                    <StatusBadge status={r.status} />
                </div>
                {r.actions.edit && <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/reservations/${r.id}/edit`)}>{t('ui.edit')}</LinkButton>}
            </div>
            <div className="sp-section" style={{ borderTop: 0, paddingTop: 0 }}>
                <KeyValue items={[{ label: t('reservations.fields.booking_id'), value: <a className="id-link num" href={reservationUrl(r.id)}>{r.ref}</a> }]} />
            </div>
            <div className="panel-tabs" style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={(['overview', 'guest', 'rooms', 'payments', 'notes', 'history'] as const).map((k) => ({ key: k, label: t(`reservations.panel_tabs.${k}`) }))} />
            </div>
            <div className="sp-section">
                {tab === 'overview' && <div className="stack">
                    <div className="stay-box">
                        <div><span className="field-label">{t('reservations.fields.check_in')}</span><strong>{date(r.check_in)}</strong><span className="muted text-sm">{r.arrival_time}</span></div>
                        <div><span className="field-label">{t('reservations.fields.check_out')}</span><strong>{date(r.check_out)}</strong><span className="muted text-sm">{r.departure_time}</span></div>
                        <div><span className="field-label">{t('reservations.fields.nights')}</span><strong className="num">{r.nights}</strong></div>
                    </div>
                    {room && <div className="stay-box">
                        <div><span className="field-label">{t('reservations.columns.room_no')}</span><strong>{room.unit?.name ?? t('reservations.not_assigned')}</strong></div>
                        <div><span className="field-label">{t('reservations.fields.room_type')}</span><strong>{room.room_type ? `${room.room_type.name} (${room.room_type.code})` : '—'}</strong></div>
                        <div><span className="field-label">{t('reservations.fields.rate_plan')}</span><strong>{room.rate_plan.name}{room.rate_plan.code ? ` (${room.rate_plan.code})` : ''}</strong></div>
                    </div>}
                    {r.rooms.length > 1 && <p className="muted text-sm">{t('reservations.rooms_count', { count: r.room_count })}</p>}
                    <div className="stay-box two">
                        <div><span className="field-label">{t('reservations.fields.guests')}</span><strong>{t('reservations.adults_children', { adults: r.adults, children: r.children })}</strong></div>
                        <div><span className="field-label">{t('reservations.fields.source')}</span><strong>{r.source?.name ?? '—'}</strong></div>
                    </div>
                    <PriceSummary r={r} />
                </div>}
                {tab === 'guest' && (r.guest_profile ? <KeyValue items={[
                    { label: t('reservations.detail.full_name'), value: <span className="row"><Flag code={r.guest_profile.nationality} />{r.guest.name}</span> },
                    { label: t('guests.fields.number'), value: r.guest_profile.number },
                    { label: t('guests.fields.email'), value: r.guest_profile.email },
                    { label: t('guests.fields.phone'), value: r.guest_profile.phone },
                    { label: t('guests.fields.id_type'), value: r.guest_profile.id_type ? t(`guests.id_types.${r.guest_profile.id_type}`) : null },
                    { label: t('guests.fields.id_number'), value: r.guest_profile.id_number },
                    { label: t('guests.fields.company_name'), value: r.guest_profile.company_name },
                    { label: t('guests.tags'), value: r.guest_profile.tags.length ? <span className="row" style={{ flexWrap: 'wrap' }}>{r.guest_profile.tags.map((g) => <Badge key={g} size="sm" tone="slate">{g}</Badge>)}</span> : null },
                ]} /> : <EmptyState icon="user" title="—" />)}
                {tab === 'rooms' && <div className="stack">
                    {r.rooms.map((x, i) => (
                        <div key={x.id} className="info-box">
                            <div className="row-between"><strong>{t('reservations.detail.room_n', { n: i + 1 })} · {x.room_type?.name}</strong><StatusBadge size="sm" status={x.status} /></div>
                            <div className="text-sm muted">{date(x.check_in)} – {date(x.check_out)} · {x.rate_plan.name} · <GuestCount adults={x.adults} children={x.children} infants={x.infants} /></div>
                            <div className="row-between text-sm"><span>{x.unit?.name ?? t('reservations.not_assigned')}</span><Money value={x.grand_total} currency={r.currency} /></div>
                        </div>
                    ))}
                </div>}
                {tab === 'payments' && <BillingSlot name="PaymentsTab" reservation={billingRef(r)} onChanged={load} compact />}
                {tab === 'notes' && <PanelNotes id={r.id} canAdd={r.actions.note} />}
                {tab === 'history' && <PanelHistory id={r.id} />}
            </div>
            <div className="sp-section">
                <h3>{t('ui.actions')}</h3>
                <div className="action-grid">
                    <LinkButton variant="outline" icon="file-text" href={reservationUrl(r.id, 'payments')}>{t('reservations.actions.view_folio')}</LinkButton>
                    {r.actions.edit && <LinkButton variant="outline" icon="pencil" href={propertyUrl(`/reservations/${r.id}/edit`)}>{t('reservations.actions.modify')}</LinkButton>}
                    {r.actions.cancel && <Button variant="danger-soft" icon="x" onClick={() => setDialog({ kind: 'cancel' })}>{t('reservations.actions.cancel')}</Button>}
                    {r.actions.email && <Button variant="outline" icon="mail" disabled={!r.guest.email} onClick={() => setEmailOpen(true)}>{t('reservations.actions.send_email')}</Button>}
                    <LinkButton variant="outline" icon="printer" href={`${reservationUrl(r.id)}?print=1`} target="_blank" rel="noopener">{t('reservations.actions.print_confirmation')}</LinkButton>
                    <Dropdown align="right" items={more} trigger={(toggle) => <Button variant="outline" iconRight="chevron-down" onClick={toggle}>{t('reservations.actions.more')}</Button>} />
                </div>
            </div>
            {emailOpen && <ComposeEmailDialog open onClose={() => setEmailOpen(false)} url={propertyApiUrl(`/reservations/${r.id}/email`)} to={r.guest.email} withConfirmation subject={r.ref} />}
            <ReservationDialogs reservation={r} kind={dialog?.kind ?? null} roomId={dialog?.room} onClose={() => setDialog(null)} onDone={changed}
                onAssign={(roomId) => setDialog({ kind: 'assign', room: roomId })} />
        </SidePanel>
    );
}

export function PriceSummary({ r }: { r: ReservationDetail }) {
    return (
        <div className="price-summary">
            <h4>{t('reservations.form.price_summary')}</h4>
            <div className="ps-row"><span>{t('reservations.form.room_charges', { count: r.nights })}</span><Money value={r.totals.room_total} currency={r.currency} /></div>
            {Number(r.totals.extras_total) !== 0 && <div className="ps-row"><span>{t('reservations.form.extra_charges')}</span><Money value={r.totals.extras_total} currency={r.currency} /></div>}
            {Number(r.totals.discount_total) !== 0 && <div className="ps-row"><span>{t('reservations.form.discount')}</span><Money value={`-${r.totals.discount_total}`} currency={r.currency} /></div>}
            <div className="ps-row"><span>{t('reservations.form.taxes_rate', { rate: Number(r.totals.tax_rate) })}</span><Money value={r.totals.tax_total} currency={r.currency} /></div>
            <div className="ps-row total"><span>{t('reservations.form.total')}</span><Money value={r.totals.grand_total} currency={r.currency} strong /></div>
            <div className="ps-row"><span>{t('reservations.detail.amount_paid')}</span><Money value={r.totals.paid} currency={r.currency} /></div>
            <div className="ps-row"><span>{t('reservations.detail.balance')}</span><Money value={r.totals.balance} currency={r.currency} strong /></div>
        </div>
    );
}

function PanelNotes({ id, canAdd }: { id: string; canAdd: boolean }) {
    const h = useHistory(id);
    return <NotesBox h={h} id={id} canAdd={canAdd} />;
}

function PanelHistory({ id }: { id: string }) {
    const h = useHistory(id);
    return <HistoryList h={h} />;
}
