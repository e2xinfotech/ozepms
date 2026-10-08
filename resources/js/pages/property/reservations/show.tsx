import { useEffect, useRef, useState } from 'react';
import { Badge, Button, Card, Flag, Icon, KeyValue, LinkButton, PageHeader, Segmented, Select, toast } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date, dateTime, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { BillingSlot, hasBilling } from './_components/BillingSlot';
import { GuestCount, Money, StatusBadge } from './_components/bits';
import { OccupantsCard } from './_components/Occupants';
import { DocumentsList, HistoryList, NotesBox, useHistory } from './_components/History';
import { ReservationDialogs, type DialogKind } from './_components/ReservationDialogs';
import { billingRef, type ReservationDetail, type ReservationRoomDetail } from './_components/types';

interface Props { reservation: ReservationDetail; tab: string }

const TABS = ['overview', 'guest', 'rooms', 'payments', 'notes', 'documents'] as const;
const DOC_TYPES = ['id_front', 'id_back', 'passport', 'visa', 'registration_card', 'other'];
const ID_TYPES = ['passport', 'national_id', 'driving_licence', 'voter_id', 'other'];

/** Reservation details (design reservation-details.png; "Check-out" spelled correctly). */
function ReservationShow({ reservation, tab: initialTab }: Props) {
    const [r, setR] = useState(reservation);
    const [tab, setTabState] = useState<string>(TABS.includes(initialTab as typeof TABS[number]) ? initialTab : 'overview');
    const [dialog, setDialog] = useState<{ kind: DialogKind; room?: string } | null>(null);
    const [billingModal, setBillingModal] = useState<'payment' | 'charge' | null>(null);

    const setTab = (k: string) => {
        setTabState(k);
        const url = new URL(window.location.href);
        if (k === 'overview') url.searchParams.delete('tab'); else url.searchParams.set('tab', k);
        window.history.replaceState(null, '', url.toString());
    };
    const reload = async () => {
        try {
            const res = await http.get<{ reservation: ReservationDetail }>(propertyApiUrl(`/reservations/${r.id}`));
            setR(res.reservation);
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        }
    };
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('print') === '1') setTimeout(() => window.print(), 300);
    }, []);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(r.ref);
            toast.success(t('reservations.actions.copied'));
        } catch {
            toast.info(r.ref);
        }
    };
    const cur = r.currency;
    const guest = r.guest_profile;
    const live = r.rooms.filter((x) => !['cancelled', 'no_show'].includes(x.status));
    const firstRoom = live[0] ?? r.rooms[0];
    const paid = Number(r.totals.paid);
    const payStatus = paid <= 0 ? 'unpaid' : Number(r.totals.balance) > 0 ? 'partial' : 'paid';

    return (
        <div className="content res-detail">
            <PageHeader back={propertyUrl('/reservations')}
                title={<span className="row" style={{ flexWrap: 'wrap', gap: 12 }}>{t('reservations.details_title')}<span className="ref-chip num">{r.ref}</span><StatusBadge status={r.status} /></span>}
                description={<>{t('reservations.created_on', { date: dateTime(r.created_at) })} · {t('reservations.source_label', { source: r.source?.name ?? '—' })}</>}
                actions={<>
                    <Button icon="printer" onClick={() => window.print()}>{t('reservations.actions.print')}</Button>
                    <Button icon="copy" onClick={copy}>{t('reservations.actions.copy')}</Button>
                    {r.actions.cancel && <Button variant="danger-soft" icon="circle-x" onClick={() => setDialog({ kind: 'cancel' })}>{t('reservations.actions.cancel')}</Button>}
                    {r.actions.edit && <LinkButton variant="primary" icon="pencil" href={propertyUrl(`/reservations/${r.id}/edit`)}>{t('reservations.actions.edit')}</LinkButton>}
                </>} />

            {r.status === 'cancelled' && r.cancellation.at && <div className="alert danger" role="status"><Icon name="circle-x" size={18} /><div>
                {t('reservations.detail.cancelled_info', { date: dateTime(r.cancellation.at), fee: money(r.cancellation.fee ?? '0', cur) })}
                {r.cancellation.reason && <> · {t('reservations.detail.cancel_reason', { reason: r.cancellation.reason })}</>}
            </div></div>}

            <div className="res-strips">
                <div className="card res-strip">
                    <div className="rs-cell rs-guest">
                        <span className="field-label">{t('reservations.detail.guest_name')}</span>
                        <div className="row" style={{ alignItems: 'flex-start' }}>
                            <Flag code={r.guest.nationality} large />
                            <div>
                                <div className="strong rs-big">{r.guest.name}</div>
                                {r.guest.phone && <div className="text-sm"><Icon name="phone" size={14} /> {r.guest.phone}</div>}
                                {r.guest.email && <div className="text-sm"><Icon name="mail" size={14} /> {r.guest.email}</div>}
                            </div>
                        </div>
                    </div>
                    <div className="rs-cell"><span className="field-label">{t('reservations.fields.check_in')}</span><div className="row"><Icon name="calendar-days" size={20} /><div><div className="strong rs-big">{date(r.check_in)}</div><div className="text-sm muted">{r.arrival_time}</div></div></div></div>
                    <div className="rs-cell"><span className="field-label">{t('reservations.fields.check_out')}</span><div className="row"><Icon name="calendar-days" size={20} /><div><div className="strong rs-big">{date(r.check_out)}</div><div className="text-sm muted">{r.departure_time}</div></div></div></div>
                    <div className="rs-cell"><span className="field-label">{t('reservations.fields.nights')}</span><div className="row"><Icon name="moon" size={20} /><span className="strong rs-big num">{r.nights}</span></div></div>
                    <div className="rs-cell"><span className="field-label">{t('reservations.fields.guests')}</span><div className="row"><Icon name="users" size={20} /><div className="text-sm">{t('reservations.adults_count', { count: r.adults })}<br />{t('reservations.children_count', { count: r.children })}</div></div></div>
                </div>
                <div className="card res-strip money">
                    <div className="rs-cell"><span className="field-label">{t('reservations.detail.total_amount')}</span><div className="row"><Icon name="layers" size={20} /><span className="strong rs-big num">{money(r.totals.grand_total, cur)}</span></div></div>
                    <div className="rs-cell"><span className="field-label">{t('reservations.detail.amount_paid')}</span><div className="row"><Icon name="banknote" size={20} className="text-success" /><span className="strong rs-big num">{money(r.totals.paid, cur)}</span></div><Badge size="sm" status={payStatus}>{t(`reservations.payment_status.${payStatus}`)}</Badge></div>
                    <div className="rs-cell"><span className="field-label">{t('reservations.detail.balance')}</span><div className="row"><Icon name="wallet" size={20} className="text-danger" /><span className="strong rs-big num">{money(r.totals.balance, cur)}</span></div></div>
                </div>
            </div>

            <Segmented active={tab} onChange={setTab} items={TABS.map((k) => ({ key: k, label: t(`reservations.tabs_detail.${k}`) }))} />

            {tab === 'overview' && <div className="res-grid">
                <div className="dash-col">
                    <Card title={t('reservations.detail.room_rate')} actions={r.actions.edit && <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/reservations/${r.id}/edit`)}>{t('ui.edit')}</LinkButton>}>
                        <div className="stack">{r.rooms.map((x, i) => <RoomLine key={x.id} room={x} index={i} multi={r.rooms.length > 1} />)}</div>
                    </Card>
                    <div className="res-grid-2">
                        <Card title={t('reservations.detail.guest_info')} actions={r.actions.edit && <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/reservations/${r.id}/edit#guest`)}>{t('ui.edit')}</LinkButton>}>
                            <KeyValue items={[
                                { label: t('reservations.detail.full_name'), value: r.guest.name },
                                { label: t('guests.fields.email'), value: guest?.email },
                                { label: t('guests.fields.phone'), value: guest?.phone },
                                { label: t('guests.fields.nationality_iso2'), value: guest?.nationality ? <span className="row"><Flag code={guest.nationality} />{guest.nationality}</span> : null },
                                { label: t('guests.fields.id_type'), value: guest?.id_type ? t(`guests.id_types.${guest.id_type}`) : null },
                                { label: t('guests.fields.id_number'), value: guest?.id_number },
                            ]} />
                        </Card>
                        <Card title={t('reservations.detail.stay_details')} actions={r.actions.edit && <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/reservations/${r.id}/edit`)}>{t('ui.edit')}</LinkButton>}>
                            <KeyValue items={[
                                { label: t('reservations.fields.arrival_time'), value: r.arrival_time },
                                { label: t('reservations.fields.departure_time'), value: r.departure_time },
                                { label: t('reservations.fields.nights'), value: r.nights },
                                { label: t('reservations.fields.purpose'), value: r.purpose ? t(`reservations.purposes.${r.purpose}`) : null },
                                { label: t('reservations.fields.special_requests'), value: r.special_requests },
                                { label: t('reservations.fields.internal_notes'), value: r.internal_notes },
                            ]} />
                        </Card>
                    </div>
                    <Card title={t('reservations.detail.additional_info')}>
                        <div className="res-grid-2">
                            <KeyValue items={[
                                { label: t('reservations.fields.source'), value: r.source?.name },
                                { label: t('reservations.fields.market'), value: r.market ? t(`reservations.markets.${r.market}`) : null },
                                { label: t('reservations.fields.created_by'), value: r.created_by },
                                { label: t('reservations.fields.created_on'), value: dateTime(r.created_at) },
                            ]} />
                            <KeyValue items={[
                                { label: t('reservations.fields.modified_by'), value: r.updated_by },
                                { label: t('reservations.fields.modified_on'), value: dateTime(r.updated_at) },
                                { label: t('reservations.fields.channel_ref'), value: r.channel_ref },
                                { label: t('reservations.fields.company'), value: r.company_name ?? r.travel_agent },
                            ]} />
                        </div>
                    </Card>
                </div>
                <div className="dash-col">
                    <Card title={t('reservations.detail.price_breakdown')} actions={<span className="badge tone-slate">{cur}</span>}>
                        <div className="price-summary">
                            <div className="ps-row"><span>{t('reservations.form.room_charges', { count: r.nights })}</span><Money value={r.totals.room_total} currency={cur} /></div>
                            <div className="ps-row"><span>{t('reservations.form.extra_charges')}</span><Money value={r.totals.extras_total} currency={cur} /></div>
                            {(r.extras_by_department ?? []).map((x) => <div key={x.department} className="ps-row sub" title={`${t('billing.fields.tax')}: ${money(x.tax, cur)}`}><span>{t(`billing.departments.${x.department}`)}</span><Money value={x.amount} currency={cur} /></div>)}
                            <div className="ps-row"><span>{t('reservations.form.discount')}</span><Money value={r.totals.discount_total} currency={cur} /></div>
                            {r.offers.map((o, i) => <div key={o.id ?? i} className="ps-row sub"><span>{o.name}{o.promo_code ? ` (${o.promo_code})` : ''}</span><Money value={o.amount} currency={cur} /></div>)}
                            <div className="ps-row strong"><span>{t('reservations.form.subtotal')}</span><Money value={r.totals.subtotal} currency={cur} strong /></div>
                            <div className="ps-row"><span>{t('reservations.form.taxes_rate', { rate: Number(r.totals.tax_rate) })}</span><Money value={r.totals.tax_total} currency={cur} /></div>
                            <div className="ps-row total"><span>{t('reservations.form.total')}</span><Money value={r.totals.grand_total} currency={cur} strong /></div>
                        </div>
                    </Card>
                    <Card title={t('reservations.detail.payment_info')} actions={r.actions.payments && hasBilling('AddPaymentModal') && <Button size="sm" variant="outline" icon="plus" onClick={() => setBillingModal('payment')}>{t('reservations.actions.add_payment')}</Button>}>
                        <BillingSlot name="PaymentsTab" reservation={billingRef(r)} onChanged={reload} compact />
                    </Card>
                    <Card title={t('reservations.detail.quick_actions')}>
                        <div className="action-grid three">
                            {r.actions.check_in && <Button variant="primary" icon="log-in" onClick={() => setDialog({ kind: 'check_in' })}>{t('reservations.actions.check_in')}</Button>}
                            {r.actions.check_out && <Button variant="primary" icon="log-out" onClick={() => setDialog({ kind: 'check_out' })}>{t('reservations.actions.check_out')}</Button>}
                            {r.actions.confirm && <Button variant="outline" icon="check" onClick={() => setDialog({ kind: 'confirm' })}>{t('reservations.actions.confirm')}</Button>}
                            {r.actions.charges && hasBilling('AddChargeModal') && <Button variant="outline" icon="plus" onClick={() => setBillingModal('charge')}>{t('reservations.actions.add_extra_charge')}</Button>}
                            {r.actions.assign && <Button variant="outline" icon="arrow-left-right" onClick={() => setDialog({ kind: 'assign', room: firstRoom?.id })}>{t('reservations.actions.change_room')}</Button>}
                            <LinkButton variant="outline" icon="mail" href={r.guest.email ? `mailto:${r.guest.email}?subject=${encodeURIComponent(r.ref)}` : undefined}>{t('reservations.actions.send_email')}</LinkButton>
                            <Button variant="outline" icon="file-down" onClick={() => window.print()}>{t('reservations.actions.download_voucher')}</Button>
                            <Button variant="outline" icon="printer" onClick={() => setTab('payments')}>{t('reservations.actions.print_folio')}</Button>
                            <Button variant="outline" icon="sticky-note" onClick={() => setTab('notes')}>{t('reservations.actions.add_note')}</Button>
                            {r.actions.no_show && <Button variant="danger-soft" icon="ban" onClick={() => setDialog({ kind: 'no_show' })}>{t('reservations.actions.no_show')}</Button>}
                        </div>
                    </Card>
                </div>
            </div>}

            {tab === 'guest' && <div className="res-grid">
                <Card title={t('reservations.detail.guest_info')} actions={r.guest.id && <LinkButton size="sm" variant="outline" icon="user" href={propertyUrl(`/guests?selected=${r.guest.id}`)}>{t('guests.quick.edit')}</LinkButton>}>
                    {guest ? <div className="res-grid-2">
                        <KeyValue items={[
                            { label: t('guests.fields.number'), value: guest.number },
                            { label: t('guests.fields.title'), value: guest.title ? t(`guests.titles.${guest.title}`) : null },
                            { label: t('guests.fields.first_name'), value: guest.first_name },
                            { label: t('guests.fields.last_name'), value: guest.last_name },
                            { label: t('guests.fields.guest_type'), value: t(`guests.types.${guest.guest_type}`) },
                            { label: t('guests.fields.date_of_birth'), value: guest.date_of_birth ? date(guest.date_of_birth) : null },
                            { label: t('guests.fields.email'), value: guest.email },
                            { label: t('guests.fields.phone'), value: guest.phone },
                        ]} />
                        <KeyValue items={[
                            { label: t('guests.fields.nationality_iso2'), value: guest.nationality },
                            { label: t('guests.fields.address_line1'), value: guest.address.join(', ') || null },
                            { label: t('guests.fields.id_type'), value: guest.id_type ? t(`guests.id_types.${guest.id_type}`) : null },
                            { label: t('guests.fields.id_number'), value: guest.id_number },
                            { label: t('guests.fields.id_expiry'), value: guest.id_expiry ? date(guest.id_expiry) : null },
                            { label: t('guests.fields.company_name'), value: guest.company_name },
                            { label: t('guests.fields.company_tax_no'), value: guest.company_tax_no },
                            { label: t('guests.tags'), value: guest.tags.length ? <span className="row" style={{ flexWrap: 'wrap' }}>{guest.tags.map((g) => <Badge key={g} size="sm" tone="slate">{g}</Badge>)}</span> : null },
                        ]} />
                    </div> : <p className="muted">—</p>}
                </Card>
                <Card title={t('reservations.detail.other_guests')}>
                    <OccupantsCard reservationId={r.id} canRegister={!!r.can_see_ids} />
                </Card>
            </div>}

            {tab === 'rooms' && <div className="stack">
                {r.rooms.map((x, i) => (
                    <Card key={x.id} title={`${t('reservations.detail.room_n', { n: i + 1 })} · ${x.room_type?.name ?? ''}`} actions={<StatusBadge size="sm" status={x.status} />}>
                        <div className="res-grid-2">
                            <KeyValue items={[
                                { label: t('reservations.fields.unit'), value: x.units.length > 1
                                    ? <span className="stack" style={{ gap: 2 }}>{x.units.map((u) => <span key={u.from + u.id}><strong>{u.name}</strong> <span className="muted num">{date(u.from)} – {date(addNight(u.to))}</span></span>)}<Badge size="sm" tone="blue">{t('reservations.split_badge', { count: x.units.length })}</Badge></span>
                                    : (x.unit?.name ?? t('reservations.not_assigned')) },
                                { label: t('reservations.fields.rate_plan'), value: `${x.rate_plan.name ?? ''}${x.rate_plan.code ? ` (${x.rate_plan.code})` : ''}` },
                                { label: t('reservations.fields.meal_plan'), value: x.meal_plan },
                                { label: t('reservations.fields.policy'), value: <span className="row">{x.policy.name}<Badge size="sm" status={x.policy.refundable ? 'refundable' : 'non_refundable'} /></span> },
                                { label: t('reservations.fields.check_in'), value: date(x.check_in) },
                                { label: t('reservations.fields.check_out'), value: date(x.check_out) },
                                { label: t('reservations.fields.guests'), value: <GuestCount adults={x.adults} children={x.children} infants={x.infants} /> },
                                { label: t('reservations.form.total'), value: <Money value={x.grand_total} currency={cur} strong /> },
                            ]} />
                            <table className="table">
                                <thead><tr><th>{t('reservations.detail.date')}</th><th className="num">{t('reservations.detail.price')}</th><th className="num">{t('reservations.detail.tax')}</th></tr></thead>
                                <tbody>{x.nightly.map((n) => <tr key={n.date}><td>{date(n.date)}</td><td className="num">{money(n.net, cur)}</td><td className="num">{money(n.tax, cur)}</td></tr>)}</tbody>
                            </table>
                        </div>
                    </Card>
                ))}
            </div>}

            {tab === 'payments' && <div className="stack">
                <BillingSlot name="FolioTab" reservation={billingRef(r)} onChanged={reload} />
                {hasBilling('PaymentsTab') && <BillingSlot name="PaymentsTab" reservation={billingRef(r)} onChanged={reload} />}
                {hasBilling('InvoiceList') && <BillingSlot name="InvoiceList" reservation={billingRef(r)} onChanged={reload} />}
            </div>}

            {tab === 'notes' && <NotesTab r={r} />}
            {tab === 'documents' && <DocumentsTab r={r} />}

            <ReservationDialogs reservation={r} kind={dialog?.kind ?? null} roomId={dialog?.room} onClose={() => setDialog(null)} onDone={setR}
                idTypes={ID_TYPES.map((v) => ({ value: v, label: t(`guests.id_types.${v}`) }))}
                onAssign={(roomId) => setDialog({ kind: 'assign', room: roomId })} />
            {billingModal === 'payment' && <BillingSlot name="AddPaymentModal" reservation={billingRef(r)} open onClose={() => setBillingModal(null)} onSaved={() => { setBillingModal(null); reload(); }} fallback={null} />}
            {billingModal === 'charge' && <BillingSlot name="AddChargeModal" reservation={billingRef(r)} open onClose={() => setBillingModal(null)} onSaved={() => { setBillingModal(null); reload(); }} fallback={null} />}
        </div>
    );
}

function RoomLine({ room: x, index, multi }: { room: ReservationRoomDetail; index: number; multi: boolean }) {
    return (
        <div className="room-line">
            <div className="rl-thumb">{x.room_type?.image ? <img src={x.room_type.image} alt="" /> : <Icon name="bed-double" size={28} />}</div>
            <div><span className="field-label">{t('reservations.fields.room_type')}</span><div className="strong">{x.room_type ? `${x.room_type.name} (${x.room_type.code})` : '—'}</div>{multi && <div className="text-sm muted">{t('reservations.detail.room_n', { n: index + 1 })}</div>}</div>
            <div><span className="field-label">{t('reservations.fields.unit')}</span><div className="strong">{x.unit?.name ?? '—'}</div><StatusBadge size="sm" status={x.status} /></div>
            <div><span className="field-label">{t('reservations.fields.rate_plan')}</span><div className="strong">{x.rate_plan.name}{x.rate_plan.code ? ` (${x.rate_plan.code})` : ''}</div><Badge size="sm" status={x.policy.refundable ? 'refundable' : 'non_refundable'} /></div>
            <div><span className="field-label">{t('reservations.fields.adults_children')}</span><div className="strong num">{x.adults} / {x.children}</div></div>
            <div><span className="field-label">{t('reservations.fields.extra_beds')}</span><div className="strong">—</div></div>
        </div>
    );
}

function NotesTab({ r }: { r: ReservationDetail }) {
    const h = useHistory(r.id);
    return (
        <div className="res-grid">
            <Card title={t('reservations.tabs_detail.notes')}><NotesBox h={h} id={r.id} canAdd={r.actions.note} /></Card>
            <Card title={t('reservations.detail.history')}><HistoryList h={h} /></Card>
        </div>
    );
}

function DocumentsTab({ r }: { r: ReservationDetail }) {
    const h = useHistory(r.id);
    const [type, setType] = useState('passport');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const upload = async (file: File) => {
        if (!r.guest.id) return;
        setBusy(true);
        const form = new FormData();
        form.append('file', file);
        form.append('type', type);
        form.append('reservation_id', r.id);
        try {
            const res = await http.post<{ message: string }>(propertyApiUrl(`/guests/${r.guest.id}/documents`), form);
            toast.success(res.message);
            h.load();
        } catch (e) {
            const err = e as ApiError;
            toast.error(err.field('file') ?? err.message, err.ref);
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };
    return (
        <Card title={t('reservations.detail.documents')} actions={r.guest.id && <div className="row">
            <Select size="sm" aria-label={t('guests.fields.document_type')} value={type} options={DOC_TYPES.map((v) => ({ value: v, label: t(`guests.document_types.${v}`) }))} onChange={(e) => setType(e.target.value)} />
            <Button size="sm" variant="outline" icon="upload" loading={busy} onClick={() => input.current?.click()}>{t('reservations.detail.upload_document')}</Button>
            <input ref={input} type="file" hidden accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
        </div>}>
            <DocumentsList h={h} />
        </Card>
    );
}

createPage(ReservationShow);

/** Last night + 1 day: the day the guest leaves that PMS room. */
function addNight(iso: string): string {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
