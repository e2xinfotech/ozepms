import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Badge, Button, Dropdown, EmptyState, FormSection, Icon, Input, LinkButton, PageHeader, Select, Stepper, Textarea, toast, type Option } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { payload, propertyApiUrl, propertyUrl } from '@/lib/page';
import { useDebounced } from '@/lib/use';
import { reservationUrl, StatusBadge } from './_components/bits';
import type { ReservationDetail } from './_components/types';

interface RoomTypeOption extends Option { name: string; code: string; base_adults: number; max_adults: number; max_children: number; max_infants: number }
interface RatePlanOption extends Option { name: string; code: string }
interface Props {
    reservation: ReservationDetail | null;
    guest: Partial<GuestForm> & { id?: string } | null;
    options: {
        sources: Option[]; room_types: RoomTypeOption[]; rate_plans: RatePlanOption[]; products: Record<string, string[]>;
        countries: (Option & { phone: string | null })[]; purposes: Option[]; markets: Option[]; titles: Option[]; guest_types: Option[]; id_types: Option[];
    };
    defaults: { today: string; check_in_time: string; check_out_time: string };
}

interface GuestForm {
    title: string; guest_type: string; first_name: string; last_name: string; email: string; phone: string; nationality_iso2: string;
    id_type: string; id_number: string; company_name: string; notes: string;
}
interface RoomRow {
    key: string; id: string | null; status: string | null; room_type_id: string; rate_plan_id: string; unit_id: string;
    check_in: string; check_out: string; adults: number; children: number; infants: number; rate: string; locked: boolean;
}
interface SearchRow {
    room_type: { id: string; code: string; name: string; max_adults: number; image: string | null }; rate_plan: { id: string; code: string; name: string };
    meal_plan: string | null; policy: { name: string | null; refundable: boolean }; available: number; sellable: boolean;
    reasons: { code: string; label: string }[]; total: string | null; average: string | null;
}
interface Quote { currency: string; rooms: { room_total: string; tax_total: string; grand_total: string; rate: string | null; nights: number }[]; room_total: string; tax_total: string; grand_total: string }

const addDays = (d: string, n: number) => {
    const x = new Date(`${d}T00:00:00`);
    x.setDate(x.getDate() + n);
    return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
};
const nightsBetween = (a: string, b: string) => Math.max(0, Math.round((new Date(`${b}T00:00:00`).getTime() - new Date(`${a}T00:00:00`).getTime()) / 86400000));
let rowSeq = 0;
const newKey = () => `r${++rowSeq}`;
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/** Create / edit reservation (designs reservation-create-search.png + reservation-create-form.png). */
function ReservationForm({ reservation: res, guest: presetGuest, options, defaults }: Props) {
    const editing = res !== null;
    const cur = payload().shell.property?.currency ?? '';
    const g = res?.guest_profile;
    const [guestId, setGuestId] = useState<string | null>(g?.id ?? presetGuest?.id ?? null);
    const [guest, setGuest] = useState<GuestForm>({
        title: g?.title ?? presetGuest?.title ?? '', guest_type: g?.guest_type ?? presetGuest?.guest_type ?? 'individual',
        first_name: g?.first_name ?? presetGuest?.first_name ?? '', last_name: g?.last_name ?? presetGuest?.last_name ?? '',
        email: g?.email ?? presetGuest?.email ?? '', phone: g?.phone ?? presetGuest?.phone ?? '', nationality_iso2: g?.nationality ?? presetGuest?.nationality_iso2 ?? '',
        id_type: g?.id_type ?? presetGuest?.id_type ?? '', id_number: '', company_name: g?.company_name ?? presetGuest?.company_name ?? '', notes: g?.notes ?? '',
    });
    const [stay, setStay] = useState({
        check_in: res?.check_in ?? defaults.today, check_out: res?.check_out ?? addDays(defaults.today, 1),
        arrival_time: res?.arrival_time ?? defaults.check_in_time, departure_time: res?.departure_time ?? defaults.check_out_time,
        adults: 2, children: 0, infants: 0,
        purpose: res?.purpose ?? '', source: res?.source?.code ?? 'direct', market: res?.market ?? '', travel_agent: res?.travel_agent ?? '',
        company_name: res?.company_name ?? '', special_requests: res?.special_requests ?? '', internal_notes: res?.internal_notes ?? '', status: 'confirmed',
    });
    const [rooms, setRooms] = useState<RoomRow[]>(() => (res?.rooms ?? []).filter((x) => !['cancelled', 'no_show', 'checked_out'].includes(x.status)).map((x) => ({
        key: newKey(), id: x.id, status: x.status, room_type_id: x.room_type?.id ?? '', rate_plan_id: x.rate_plan.id ?? '', unit_id: x.unit?.id ?? '',
        check_in: x.check_in, check_out: x.check_out, adults: x.adults, children: x.children, infants: x.infants, rate: x.manual_rate ?? '', locked: x.status === 'checked_in',
    })));
    const [results, setResults] = useState<SearchRow[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [quote, setQuote] = useState<Quote | null>(null);
    const [quoteError, setQuoteError] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [saving, setSaving] = useState<'' | 'draft' | 'confirm'>('');
    const [review, setReview] = useState(false);
    const [dirty, setDirty] = useState(false);
    const idempotency = useRef(uuid());
    const err = (k: string) => error?.field(k);

    const setG = <K extends keyof GuestForm>(k: K, v: GuestForm[K]) => { setGuest((x) => ({ ...x, [k]: v })); setDirty(true); };
    const setS = <K extends keyof typeof stay>(k: K, v: (typeof stay)[K]) => { setStay((x) => ({ ...x, [k]: v })); setDirty(true); };
    const setRoom = (key: string, patch: Partial<RoomRow>) => { setRooms((list) => list.map((r) => (r.key === key ? { ...r, ...patch } : r))); setDirty(true); };
    const roomType = (id: string) => options.room_types.find((r) => r.value === id);
    const plansFor = (rt: string) => (options.products[rt] ?? []).map((id) => options.rate_plans.find((p) => p.value === id)).filter(Boolean) as RatePlanOption[];

    // Warn before leaving with unsaved changes.
    useEffect(() => {
        const h = (e: BeforeUnloadEvent) => { if (dirty && !saving) e.preventDefault(); };
        window.addEventListener('beforeunload', h);
        return () => window.removeEventListener('beforeunload', h);
    }, [dirty, saving]);

    // Stay dates apply to every room that is not in-house.
    const changeDates = (patch: { check_in?: string; check_out?: string }) => {
        const next = { ...stay, ...patch };
        if (next.check_out <= next.check_in) next.check_out = addDays(next.check_in, 1);
        setStay(next);
        setDirty(true);
        setRooms((list) => list.map((r) => (r.locked ? { ...r, check_out: next.check_out } : { ...r, check_in: next.check_in, check_out: next.check_out })));
    };

    const search = async () => {
        setSearching(true);
        try {
            const out = await http.get<{ rows: SearchRow[] }>(propertyApiUrl('/reservations/availability'), {
                check_in: stay.check_in, check_out: stay.check_out, adults: stay.adults, children: stay.children, infants: stay.infants,
            });
            setResults(out.rows);
        } catch (e) {
            const er = e as ApiError;
            toast.error(Object.values(er.fields)[0]?.[0] ?? er.message, er.ref);
        } finally {
            setSearching(false);
        }
    };

    const addRoom = (rt: string = String(options.room_types[0]?.value ?? ''), rp?: string) => {
        const plan = rp ?? plansFor(rt)[0]?.value?.toString() ?? '';
        setRooms((list) => [...list, {
            key: newKey(), id: null, status: null, room_type_id: rt, rate_plan_id: String(plan), unit_id: '', check_in: stay.check_in, check_out: stay.check_out,
            adults: Math.min(stay.adults, roomType(rt)?.max_adults ?? stay.adults), children: stay.children, infants: stay.infants, rate: '', locked: false,
        }]);
        setDirty(true);
    };

    // Live price summary (server-side pricing and taxes), debounced.
    const quoteKey = useDebounced(JSON.stringify(rooms.map((r) => [r.room_type_id, r.rate_plan_id, r.check_in, r.check_out, r.adults, r.children, r.infants, r.rate])), 400);
    useEffect(() => {
        const valid = rooms.filter((r) => r.room_type_id && r.rate_plan_id && r.check_out > r.check_in);
        if (valid.length === 0 || valid.length !== rooms.length) { setQuote(null); return; }
        let alive = true;
        http.post<Quote>(propertyApiUrl('/reservations/quote'), { rooms: rooms.map(roomPayload) })
            .then((q) => { if (alive) { setQuote(q); setQuoteError(null); } })
            .catch((e: ApiError) => { if (alive) { setQuote(null); setQuoteError(Object.values(e.fields)[0]?.[0] ?? t('reservations.form.quote_failed')); } });
        return () => { alive = false; };
    }, [quoteKey]);

    function roomPayload(r: RoomRow) {
        return {
            id: r.id, room_type_id: r.room_type_id, rate_plan_id: r.rate_plan_id, unit_id: r.unit_id || null,
            check_in: r.check_in, check_out: r.check_out, adults: r.adults, children: r.children, infants: r.infants, rate: r.rate === '' ? null : r.rate,
        };
    }

    const body = (status?: string) => ({
        idempotency_key: editing ? undefined : idempotency.current,
        status: editing ? undefined : status,
        source: stay.source || null, arrival_time: stay.arrival_time || null, departure_time: stay.departure_time || null,
        purpose: stay.purpose || null, market: stay.market || null, travel_agent: stay.travel_agent || null, company_name: stay.company_name || null,
        special_requests: stay.special_requests || null, internal_notes: stay.internal_notes || null,
        guest_id: guestId,
        guest: {
            title: guest.title || null, guest_type: guest.guest_type || null, first_name: guest.first_name, last_name: guest.last_name || null,
            email: guest.email || null, phone: guest.phone || null, nationality_iso2: guest.nationality_iso2 || null,
            id_type: guest.id_type || null, ...(guest.id_number ? { id_number: guest.id_number } : {}), company_name: guest.company_name || null,
        },
        rooms: rooms.map(roomPayload),
    });

    const focusFirstError = () => setTimeout(() => {
        const el = document.querySelector<HTMLElement>('.field-error, .alert.danger');
        el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el?.closest('.field')?.querySelector<HTMLElement>('input, select, textarea')?.focus();
    }, 50);

    const submit = async (status: 'confirmed' | 'pending' | 'inquiry') => {
        if (saving) return;
        setSaving(status === 'inquiry' ? 'draft' : 'confirm');
        setError(null);
        try {
            const out = editing
                ? await http.put<{ message: string; reservation: { id: string } }>(propertyApiUrl(`/reservations/${res.id}`), body())
                : await http.post<{ message: string; reservation: { id: string } }>(propertyApiUrl('/reservations'), body(status));
            toast.success(out.message);
            setDirty(false);
            window.location.href = reservationUrl(out.reservation.id);
        } catch (e) {
            const er = e as ApiError;
            setError(er);
            setReview(false);
            toast.error(Object.values(er.fields)[0]?.[0] ?? er.message, er.status >= 500 ? er.ref : undefined);
            focusFirstError();
            setSaving('');
        }
    };

    const nights = nightsBetween(stay.check_in, stay.check_out);
    const guestDone = guest.first_name.trim() !== '' && nights > 0;
    const roomsDone = rooms.length > 0 && rooms.every((r) => r.room_type_id && r.rate_plan_id);
    const step = review ? 3 : !guestDone ? 0 : !roomsDone ? 1 : 2;
    const firstRow = rooms[0];
    const sumGuests = rooms.reduce((a, r) => ({ adults: a.adults + r.adults, children: a.children + r.children }), { adults: 0, children: 0 });
    const canReview = guestDone && roomsDone;

    return (
        <div className="content res-form">
            <PageHeader back={editing ? reservationUrl(res.id) : propertyUrl('/reservations')}
                title={editing ? <span className="row" style={{ gap: 12 }}>{t('reservations.edit_title')}<span className="ref-chip num">{res.ref}</span><StatusBadge status={res.status} /></span> : t('reservations.create_title')}
                description={editing ? t('reservations.edit_description') : t('reservations.create_description')} />
            <div className="res-form-grid">
                <div className="res-form-main">
                    <Stepper current={step} steps={[t('reservations.steps.guest'), t('reservations.steps.rooms'), t('reservations.steps.services'), t('reservations.steps.review')]} />
                    {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{Object.values(error.fields)[0]?.[0] ?? t('errors.validation')}</Alert>}

                    {review ? <ReviewBlock guest={guest} stay={stay} rooms={rooms} quote={quote} options={options} cur={cur} setStatus={(v) => setS('status', v)} editing={editing} /> : <>
                        <FormSection title={t('reservations.form.stay_search')} description={t('reservations.form.available_rooms_hint')}>
                            <Input fieldClass="span-3" type="date" label={t('reservations.fields.check_in')} required value={stay.check_in} min={editing ? undefined : defaults.today}
                                onChange={(e) => e.target.value && changeDates({ check_in: e.target.value })} error={err('rooms.0.check_in')} />
                            <Input fieldClass="span-3" type="date" label={t('reservations.fields.check_out')} required value={stay.check_out} min={addDays(stay.check_in, 1)}
                                onChange={(e) => e.target.value && changeDates({ check_out: e.target.value })} error={err('rooms.0.check_out')} />
                            <Input fieldClass="span-2" type="number" min={1} max={20} label={t('reservations.fields.adults')} value={stay.adults} onChange={(e) => setS('adults', Math.max(1, Number(e.target.value) || 1))} />
                            <Input fieldClass="span-2" type="number" min={0} max={10} label={t('reservations.fields.children')} value={stay.children} onChange={(e) => setS('children', Math.max(0, Number(e.target.value) || 0))} />
                            <Input fieldClass="span-2" type="number" min={0} max={10} label={t('reservations.fields.infants')} value={stay.infants} onChange={(e) => setS('infants', Math.max(0, Number(e.target.value) || 0))} />
                            <div className="span-12 row-between">
                                <span className="muted text-sm">{t('reservations.nights_count', { count: nights })}</span>
                                <Button variant="primary" icon="search" loading={searching} onClick={search}>{t('reservations.form.search_availability')}</Button>
                            </div>
                            {results !== null && <div className="span-12">
                                {results.length === 0 ? <EmptyState icon="bed-double" title={t('reservations.form.no_results')} /> : (
                                    <div className="table-wrap"><div className="table-scroll"><table className="table avail-table">
                                        <thead><tr>
                                            <th>{t('reservations.fields.room_type')}</th><th>{t('reservations.fields.rate_plan')}</th>
                                            <th className="num">{t('reservations.fields.rate')}</th><th className="num">{t('reservations.form.total')}</th>
                                            <th>{t('reservations.columns.status')}</th><th className="col-actions" />
                                        </tr></thead>
                                        <tbody>{results.map((row) => {
                                            const added = rooms.some((r) => r.room_type_id === row.room_type.id && r.rate_plan_id === row.rate_plan.id && !r.id);
                                            return (
                                                <tr key={`${row.room_type.id}-${row.rate_plan.id}`}>
                                                    <td><div className="cell-main">{row.room_type.name}</div><div className="cell-sub">{t('reservations.form.occupancy_short', { adults: stay.adults, children: stay.children, infants: stay.infants })}</div></td>
                                                    <td><div>{row.rate_plan.name}</div><Badge size="sm" status={row.policy.refundable ? 'refundable' : 'non_refundable'} /></td>
                                                    <td className="num">{row.average ? money(row.average, cur) : '—'}</td>
                                                    <td className="num strong">{row.total ? money(row.total, cur) : '—'}</td>
                                                    <td>{row.sellable
                                                        ? <span className="text-success text-sm strong">{t('reservations.form.rooms_left', { count: row.available })}</span>
                                                        : <span className="text-danger text-sm" title={row.reasons.map((x) => x.label).join(', ')}>{row.reasons.some((x) => x.code === 'sold_out') ? t('reservations.form.sold_out') : row.reasons.map((x) => x.label).join(', ') || t('reservations.form.not_bookable')}</span>}</td>
                                                    <td className="col-actions"><Button size="sm" variant={added ? 'secondary' : 'outline'} disabled={!row.sellable} icon={added ? 'check' : undefined}
                                                        onClick={() => addRoom(row.room_type.id, row.rate_plan.id)}>{added ? t('reservations.form.selected') : t('reservations.form.select')}</Button></td>
                                                </tr>
                                            );
                                        })}</tbody>
                                    </table></div></div>
                                )}
                            </div>}
                        </FormSection>

                        <div id="guest" />
                        <FormSection title={t('reservations.form.guest_info')} actions={<GuestSearch onPick={(found) => {
                            setGuestId(found.id);
                            setGuest((x) => ({ ...x, title: found.title ?? '', guest_type: found.guest_type ?? 'individual', first_name: found.first_name ?? '', last_name: found.last_name ?? '',
                                email: found.email ?? '', phone: found.phone ?? '', nationality_iso2: found.nationality ?? '', id_type: found.id_type ?? '', company_name: found.company_name ?? '' }));
                            setDirty(true);
                        }} />}>
                            {guestId && <div className="span-12"><Alert tone="info">{t('reservations.form.guest_selected')}{' '}
                                <button type="button" className="link-button" onClick={() => { setGuestId(null); setDirty(true); }}>{t('reservations.form.new_guest')}</button></Alert></div>}
                            <Select fieldClass="span-3" label={t('guests.fields.guest_type')} value={guest.guest_type} options={options.guest_types} onChange={(e) => setG('guest_type', e.target.value)} />
                            <Select fieldClass="span-2" label={t('guests.fields.title')} optional value={guest.title} placeholder="—" options={options.titles} onChange={(e) => setG('title', e.target.value)} />
                            <Input fieldClass="span-4" label={t('guests.fields.first_name')} required maxLength={80} value={guest.first_name} onChange={(e) => setG('first_name', e.target.value)} error={err('guest.first_name')} />
                            <Input fieldClass="span-3" label={t('guests.fields.last_name')} maxLength={80} value={guest.last_name} onChange={(e) => setG('last_name', e.target.value)} error={err('guest.last_name')} />
                            <Input fieldClass="span-4" type="email" label={t('guests.fields.email')} icon="mail" maxLength={190} value={guest.email} onChange={(e) => setG('email', e.target.value)} error={err('guest.email')} />
                            <Input fieldClass="span-3" type="tel" label={t('guests.fields.phone')} icon="phone" maxLength={25} placeholder="+44 7700 900123" value={guest.phone} onChange={(e) => setG('phone', e.target.value)} error={err('guest.phone')} />
                            <Select fieldClass="span-3" label={t('guests.fields.nationality_iso2')} value={guest.nationality_iso2} placeholder="—" options={options.countries} onChange={(e) => setG('nationality_iso2', e.target.value)} error={err('guest.nationality_iso2')} />
                            <Select fieldClass="span-2" label={t('guests.fields.id_type')} value={guest.id_type} placeholder="—" options={options.id_types} onChange={(e) => setG('id_type', e.target.value)} />
                            <Input fieldClass="span-4" label={t('guests.fields.id_number')} optional maxLength={40} value={guest.id_number} placeholder={g?.id_number ?? ''} onChange={(e) => setG('id_number', e.target.value)} error={err('guest.id_number')} />
                            <Input fieldClass="span-4" label={t('guests.fields.company_name')} optional maxLength={190} value={guest.company_name} onChange={(e) => setG('company_name', e.target.value)} />
                            <Input fieldClass="span-4" label={t('reservations.fields.special_requests')} optional maxLength={1000} value={stay.special_requests} onChange={(e) => setS('special_requests', e.target.value)} error={err('special_requests')} />
                        </FormSection>

                        <FormSection title={t('reservations.form.stay_details')}>
                            <Input fieldClass="span-3" type="time" label={t('reservations.fields.check_in_time')} value={stay.arrival_time} onChange={(e) => setS('arrival_time', e.target.value)} error={err('arrival_time')} />
                            <Input fieldClass="span-3" type="time" label={t('reservations.fields.check_out_time')} value={stay.departure_time} onChange={(e) => setS('departure_time', e.target.value)} error={err('departure_time')} />
                            <Select fieldClass="span-3" label={t('reservations.fields.source')} icon="globe" value={stay.source} options={options.sources} onChange={(e) => setS('source', e.target.value)} error={err('source')} />
                            <Select fieldClass="span-3" label={t('reservations.fields.purpose')} optional value={stay.purpose} placeholder="—" options={options.purposes} onChange={(e) => setS('purpose', e.target.value)} />
                            <Select fieldClass="span-3" label={t('reservations.fields.market')} optional value={stay.market} placeholder="—" options={options.markets} onChange={(e) => setS('market', e.target.value)} />
                            <Input fieldClass="span-3" label={t('reservations.fields.travel_agent')} optional maxLength={120} value={stay.travel_agent} onChange={(e) => setS('travel_agent', e.target.value)} />
                            <Input fieldClass="span-6" label={t('reservations.fields.company')} optional maxLength={190} value={stay.company_name} onChange={(e) => setS('company_name', e.target.value)} />
                            <Textarea fieldClass="span-12" label={t('reservations.fields.internal_notes')} optional rows={2} maxLength={5000} value={stay.internal_notes} onChange={(e) => setS('internal_notes', e.target.value)} />
                        </FormSection>

                        <FormSection title={t('reservations.form.rooms_rates')} description={t('reservations.form.manual_price_hint')}
                            actions={<Button variant="primary" size="sm" icon="plus" onClick={() => addRoom()} disabled={rooms.length >= 10 || options.room_types.length === 0}>{t('reservations.form.add_room')}</Button>}>
                            <div className="span-12">
                                {rooms.length === 0 ? <EmptyState icon="bed-double" title={t('reservations.form.no_room_selected')} text={t('reservations.form.search_first')} /> : (
                                    <div className="room-rows">
                                        {rooms.map((r, i) => <RoomRowEditor key={r.key} row={r} index={i} options={options} plans={plansFor(r.room_type_id)} quote={quote?.rooms[i] ?? null} cur={cur}
                                            err={err} onChange={(patch) => setRoom(r.key, patch)} onRemove={() => { setRooms((l) => l.filter((x) => x.key !== r.key)); setDirty(true); }} />)}
                                    </div>
                                )}
                                {quoteError && <div className="field-error" style={{ marginTop: 8 }}>{quoteError}</div>}
                            </div>
                        </FormSection>

                        <FormSection title={t('reservations.form.services')}>
                            <p className="span-12 muted">{t('reservations.form.services_hint')}</p>
                        </FormSection>
                    </>}
                </div>

                <aside className="res-form-side">
                    <div className="card">
                        <div className="card-head"><h3>{t('reservations.form.summary')}</h3></div>
                        <div className="card-body">
                            {rooms.length === 0 && <p className="muted text-sm sum-empty">{t('reservations.form.no_room_selected')} — {t('reservations.form.no_room_hint')}</p>}
                            <dl className="sum-list">
                                <dt>{t('reservations.fields.check_in')}</dt><dd>{date(stay.check_in)} <span className="muted">{stay.arrival_time}</span></dd>
                                <dt>{t('reservations.fields.check_out')}</dt><dd>{date(stay.check_out)} <span className="muted">{stay.departure_time}</span></dd>
                                <dt>{t('reservations.fields.nights')}</dt><dd className="num">{nights}</dd>
                                <dt>{t('reservations.columns.room_no')}</dt><dd className="num">{rooms.length}</dd>
                                <dt>{t('reservations.fields.guests')}</dt><dd>{t('reservations.adults_children', { adults: sumGuests.adults, children: sumGuests.children })}</dd>
                                {firstRow && <><dt>{t('reservations.fields.room_type')}</dt><dd>{roomType(firstRow.room_type_id)?.label ?? '—'}{rooms.length > 1 ? ` ${t('reservations.multiple_rooms', { count: rooms.length - 1 })}` : ''}</dd>
                                    <dt>{t('reservations.fields.rate_plan')}</dt><dd>{options.rate_plans.find((p) => p.value === firstRow.rate_plan_id)?.label ?? '—'}</dd></>}
                            </dl>
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-head"><h3>{t('reservations.form.price_summary')}</h3><span className="badge tone-slate">{cur}</span></div>
                        <div className="card-body">
                            <div className="price-summary">
                                <div className="ps-row"><span>{t('reservations.form.room_charges', { count: nights })}</span><span className="num">{money(quote?.room_total ?? '0', cur)}</span></div>
                                <div className="ps-row"><span>{t('reservations.form.extra_charges')}</span><span className="num">{money('0', cur)}</span></div>
                                <div className="ps-row"><span>{t('reservations.form.discount')}</span><span className="num">{money('0', cur)}</span></div>
                                <div className="ps-row strong"><span>{t('reservations.form.subtotal')}</span><span className="num">{money(quote?.room_total ?? '0', cur)}</span></div>
                                <div className="ps-row"><span>{t('reservations.form.taxes')}</span><span className="num">{money(quote?.tax_total ?? '0', cur)}</span></div>
                                <div className="ps-row total"><span>{t('reservations.form.total')}</span><span className="num strong">{money(quote?.grand_total ?? '0', cur)}</span></div>
                            </div>
                            <p className="text-xs muted" style={{ marginTop: 8 }}>{t('reservations.form.tax_hint')}</p>
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-head"><h3>{t('reservations.form.payment')}</h3></div>
                        <div className="card-body"><p className="text-sm muted">{t('reservations.form.payment_hint')}</p></div>
                    </div>
                </aside>
            </div>

            <div className="form-footer">
                {!editing && !review && <Button variant="outline" icon="save" loading={saving === 'draft'} disabled={!!saving || !canReview} onClick={() => submit('inquiry')}>{t('reservations.form.save_draft')}</Button>}
                {review && <Button icon="arrow-left" disabled={!!saving} onClick={() => setReview(false)}>{t('reservations.form.back')}</Button>}
                <span className="spacer" />
                <LinkButton href={editing ? reservationUrl(res.id) : propertyUrl('/reservations')}>{t('ui.cancel')}</LinkButton>
                {editing
                    ? <Button variant="primary" icon="save" loading={saving === 'confirm'} disabled={!!saving || !canReview} onClick={() => submit('confirmed')}>{t('reservations.form.save_changes')}</Button>
                    : review
                        ? <Button variant="primary" icon="check" loading={saving === 'confirm'} disabled={!!saving} onClick={() => submit(stay.status as 'confirmed' | 'pending')}>{t('reservations.form.confirm')}</Button>
                        : <Button variant="primary" iconRight="arrow-right" disabled={!canReview} onClick={() => { setReview(true); window.scrollTo({ top: 0, behavior: 'smooth' }); }}>{t('reservations.form.review')}</Button>}
            </div>
        </div>
    );
}

function RoomRowEditor({ row, index, options, plans, quote, cur, err, onChange, onRemove }: {
    row: RoomRow; index: number; options: Props['options']; plans: RatePlanOption[]; quote: Quote['rooms'][number] | null; cur: string;
    err: (k: string) => string | undefined; onChange: (patch: Partial<RoomRow>) => void; onRemove: () => void;
}) {
    const [units, setUnits] = useState<Option[] | null>(null);
    const rt = options.room_types.find((r) => r.value === row.room_type_id);
    const loadUnits = useCallback(() => {
        if (!row.room_type_id || units !== null) return;
        http.get<{ units: { id: string; name: string; housekeeping: string }[] }>(propertyApiUrl('/reservations/units'), {
            room_type_id: row.room_type_id, check_in: row.check_in, check_out: row.check_out, room_id: row.id,
        }).then((res) => setUnits(res.units.map((u) => ({ value: u.id, label: u.housekeeping === 'dirty' ? `${u.name} · ${t('ui.status.dirty')}` : u.name }))))
            .catch(() => setUnits([]));
    }, [row.room_type_id, row.check_in, row.check_out, row.id, units]);
    // Free rooms depend on the room type and the dates.
    useEffect(() => { setUnits(null); }, [row.room_type_id, row.check_in, row.check_out]);
    useEffect(() => { if (row.unit_id) loadUnits(); }, [row.unit_id, loadUnits]);
    const unitOptions = useMemo(() => units ?? (row.unit_id ? [{ value: row.unit_id, label: '…' }] : []), [units, row.unit_id]);
    const nights = nightsBetween(row.check_in, row.check_out);
    const e = (k: string) => err(`rooms.${index}.${k}`);

    return (
        <div className="room-row">
            <Select size="sm" label={t('reservations.fields.room_type')} value={row.room_type_id} disabled={row.locked} options={options.room_types}
                onChange={(ev) => onChange({ room_type_id: ev.target.value, rate_plan_id: (options.products[ev.target.value] ?? [])[0] ?? '', unit_id: '' })} error={e('room_type_id')} />
            <Select size="sm" label={t('reservations.fields.unit')} value={row.unit_id} placeholder={t('reservations.form.any_room')} options={unitOptions}
                onFocus={loadUnits} onMouseDown={loadUnits} onChange={(ev) => onChange({ unit_id: ev.target.value })} error={e('unit_id')} />
            <Select size="sm" label={t('reservations.fields.rate_plan')} value={row.rate_plan_id} disabled={row.locked} placeholder="—" options={plans}
                onChange={(ev) => onChange({ rate_plan_id: ev.target.value })} error={e('rate_plan_id')} />
            <Select size="sm" label={t('reservations.fields.adults')} value={row.adults} disabled={row.locked}
                options={Array.from({ length: Math.max(1, rt?.max_adults ?? 4) }, (_, i) => ({ value: i + 1, label: String(i + 1) }))} onChange={(ev) => onChange({ adults: Number(ev.target.value) })} error={e('adults')} />
            <Select size="sm" label={t('reservations.fields.children')} value={row.children} disabled={row.locked}
                options={Array.from({ length: (rt?.max_children ?? 3) + 1 }, (_, i) => ({ value: i, label: String(i) }))} onChange={(ev) => onChange({ children: Number(ev.target.value) })} />
            <Input size="sm" type="number" min={0} step="0.01" inputMode="decimal" label={t('reservations.fields.rate')} className="num" value={row.rate}
                placeholder={quote?.rate ? money(quote.rate, cur) : t('reservations.form.auto_price')} title={t('reservations.form.manual_price_hint')}
                onChange={(ev) => onChange({ rate: ev.target.value })} error={e('rate')} />
            <div className="field rr-nights"><span className="field-label">{t('reservations.fields.nights')}</span><span className="num rr-value" title={`${date(row.check_in)} – ${date(row.check_out)}`}>{nights}</span></div>
            <div className="field rr-total"><span className="field-label">{t('reservations.form.total')}</span><span className="num strong rr-value">{quote ? money(quote.grand_total, cur) : '—'}</span></div>
            <div className="field rr-del"><span className="field-label">&nbsp;</span><Button size="sm" variant="danger-soft" icon="trash" aria-label={t('reservations.form.remove_room')} title={t('reservations.form.remove_room')} disabled={row.locked} onClick={onRemove} /></div>
            {(e('check_in') || e('check_out') || e('id')) && <div className="field-error span-row">{e('check_in') ?? e('check_out') ?? e('id')}</div>}
        </div>
    );
}

function GuestSearch({ onPick }: { onPick: (g: { id: string; title: string | null; guest_type: string | null; first_name: string | null; last_name: string | null; email: string | null; phone: string | null; nationality: string | null; id_type: string | null; company_name: string | null }) => void }) {
    const [q, setQ] = useState('');
    const dq = useDebounced(q, 250);
    const [found, setFound] = useState<Parameters<typeof onPick>[0][] | null>(null);
    useEffect(() => {
        if (dq.trim().length < 2) { setFound(null); return; }
        let alive = true;
        http.get<{ guests: Parameters<typeof onPick>[0][] }>(propertyApiUrl('/guest-lookup'), { q: dq }).then((r) => alive && setFound(r.guests)).catch(() => alive && setFound([]));
        return () => { alive = false; };
    }, [dq]);
    return (
        <Dropdown width={360} trigger={(toggle) => <Button size="sm" variant="outline" icon="search" onClick={toggle}>{t('reservations.form.search_guest')}</Button>} items={
            <div className="guest-search">
                <Input autoFocus icon="search" type="search" aria-label={t('reservations.form.search_guest')} placeholder={t('reservations.form.search_guest_placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
                {found !== null && found.length === 0 && <p className="muted text-sm">{t('ui.no_results')}</p>}
                {found?.map((g) => (
                    <button key={g.id} type="button" className="menu-item" onClick={() => onPick(g)}>
                        <Icon name="user" size={16} /><span className="grow"><span className="strong">{[g.first_name, g.last_name].filter(Boolean).join(' ')}</span><br /><span className="text-xs muted">{[g.email, g.phone].filter(Boolean).join(' · ')}</span></span>
                    </button>
                ))}
            </div>
        } />
    );
}

function ReviewBlock({ guest, stay, rooms, quote, options, cur, setStatus, editing }: {
    guest: GuestForm; stay: { status: string; check_in: string; check_out: string; source: string; special_requests: string }; rooms: RoomRow[]; quote: Quote | null;
    options: Props['options']; cur: string; setStatus: (v: string) => void; editing: boolean;
}) {
    return (
        <FormSection title={t('reservations.form.review_title')}>
            <div className="span-6"><span className="field-label">{t('reservations.detail.guest_name')}</span><div className="strong">{[guest.first_name, guest.last_name].filter(Boolean).join(' ')}</div><div className="text-sm muted">{[guest.email, guest.phone].filter(Boolean).join(' · ')}</div></div>
            <div className="span-6"><span className="field-label">{t('reservations.fields.source')}</span><div className="strong">{options.sources.find((s) => s.value === stay.source)?.label ?? '—'}</div></div>
            <div className="span-12">
                <table className="table">
                    <thead><tr><th>{t('reservations.fields.room_type')}</th><th>{t('reservations.fields.rate_plan')}</th><th>{t('reservations.fields.check_in')}</th><th>{t('reservations.fields.check_out')}</th><th className="num">{t('reservations.fields.guests')}</th><th className="num">{t('reservations.form.total')}</th></tr></thead>
                    <tbody>{rooms.map((r, i) => (
                        <tr key={r.key}>
                            <td>{options.room_types.find((x) => x.value === r.room_type_id)?.label}</td>
                            <td>{options.rate_plans.find((x) => x.value === r.rate_plan_id)?.label}</td>
                            <td>{date(r.check_in)}</td><td>{date(r.check_out)}</td>
                            <td className="num">{r.adults} + {r.children}</td>
                            <td className="num strong">{quote?.rooms[i] ? money(quote.rooms[i].grand_total, cur) : '—'}</td>
                        </tr>
                    ))}</tbody>
                </table>
            </div>
            {stay.special_requests && <div className="span-12"><span className="field-label">{t('reservations.fields.special_requests')}</span><div>{stay.special_requests}</div></div>}
            {!editing && <Select fieldClass="span-4" label={t('reservations.fields.booking_status')} value={stay.status} hint={t('reservations.form.booking_status_hint')}
                options={['confirmed', 'pending'].map((v) => ({ value: v, label: t(`reservations.status.${v}`) }))} onChange={(e) => setStatus(e.target.value)} />}
            <div className="span-8 right"><span className="field-label">{t('reservations.form.total')}</span><div className="rs-big strong num">{money(quote?.grand_total ?? '0', cur)}</div></div>
        </FormSection>
    );
}

createPage(ReservationForm);
