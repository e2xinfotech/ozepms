import { useEffect, useState } from 'react';
import { Alert, Button, Icon, Input, Select } from '@/components/ui';
import { BookingShell, bookingUrl, type BookingProperty } from '@/components/booking/BookingShell';
import { PriceLines } from '@/components/booking/PriceLines';
import { createPage } from '@/lib/boot';
import { date, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t, tc } from '@/lib/i18n';

interface Rate {
    rate_plan_id: string; name: string; description: string | null; meal_plan: string | null; breakfast: boolean; refundable: boolean; policy: string;
    payment_type: string; price_before: string; grand_before: string; room_total: string; discount: string; taxes: string; grand_total: string; per_night: string;
    offers: { name: string; promo_code: string | null }[];
    tax_lines: { name: string; rate: string | null; amount: string }[]; policy_lines: string[]; pay_now: string;
}
interface RoomResult {
    id: string; name: string; description: string | null; max_adults: number; max_children: number; max_occupancy: number; size: string | null;
    view: string | null; images: string[]; amenities: { name: string; icon: string | null }[]; left: number; rates: Rate[]; from: string;
}
export interface SearchResult {
    check_in: string; check_out: string; nights: number; adults: number; children: number; infants: number; rooms: number; currency: string;
    promo: { code: string; status: string; message: string } | null; room_types: RoomResult[];
}
interface Props { property: BookingProperty; query: Record<string, string | undefined> }

const addDays = (d: string, n: number) => {
    const x = new Date(`${d}T00:00:00`);
    x.setDate(x.getDate() + n);
    return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
};
const range = (from: number, to: number) => Array.from({ length: to - from + 1 }, (_, i) => ({ value: String(from + i), label: String(from + i) }));

/** Public booking engine: search and results (one property). */
function BookingSearch({ property, query }: Props) {
    const [form, setForm] = useState({
        check_in: query.check_in ?? property.today, check_out: query.check_out ?? addDays(query.check_in ?? property.today, 1),
        adults: query.adults ?? '2', children: query.children ?? '0', infants: query.infants ?? '0', rooms: query.rooms ?? '1', promo_code: query.promo_code ?? '',
    });
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
    const [result, setResult] = useState<SearchResult | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const cur = property.currency;

    const run = async (values = form) => {
        setBusy(true);
        setError(null);
        const params = { ...values, promo_code: values.promo_code.trim() || null };
        window.history.replaceState(null, '', bookingUrl(property.code, '', params));
        try {
            setResult(await http.get<SearchResult>(bookingUrl(property.code, '/api/search'), params));
        } catch (e) {
            setResult(null);
            setError(e as ApiError);
        } finally {
            setBusy(false);
        }
    };
    useEffect(() => { if (query.check_in && query.check_out) run(); }, []);

    const pickIn = (v: string) => setForm((f) => ({ ...f, check_in: v, check_out: f.check_out <= v ? addDays(v, 1) : f.check_out }));
    const firstError = error ? (Object.values(error.fields ?? {})[0]?.[0] ?? error.message) : null;

    return (
        <BookingShell property={property}>
            <section className="be-hero">
                <h1>{t('booking.book_online')}</h1>
                {(property.intro || property.tagline) && <p>{property.intro || property.tagline}</p>}
            </section>
            <form className="be-search card" onSubmit={(e) => { e.preventDefault(); run(); }}>
                <Input type="date" label={t('booking.search.check_in')} min={property.today} max={addDays(property.today, property.limits.max_days_ahead)}
                    value={form.check_in} onChange={(e) => pickIn(e.target.value)} error={error?.field('check_in')} />
                <Input type="date" label={t('booking.search.check_out')} min={addDays(form.check_in, 1)} max={addDays(form.check_in, property.limits.max_nights)}
                    value={form.check_out} onChange={(e) => set('check_out', e.target.value)} error={error?.field('check_out')} />
                <Select label={t('booking.search.adults')} value={form.adults} options={range(1, 10)} onChange={(e) => set('adults', e.target.value)} />
                <Select label={`${t('booking.search.children')} (${t('booking.search.years', property.age_bands.child)})`} value={form.children} options={range(0, 6)} onChange={(e) => set('children', e.target.value)} />
                <Select label={`${t('booking.search.infants')} (${t('booking.search.years', property.age_bands.infant)})`} value={form.infants} options={range(0, 4)} onChange={(e) => set('infants', e.target.value)} />
                <Select label={t('booking.search.rooms')} value={form.rooms} options={range(1, property.limits.max_rooms)} onChange={(e) => set('rooms', e.target.value)} />
                <Input label={t('booking.search.promo')} optional maxLength={30} value={form.promo_code} onChange={(e) => set('promo_code', e.target.value.toUpperCase())} />
                <div className="be-search-go"><Button type="submit" variant="primary" icon="search" loading={busy}>{t('booking.search.button')}</Button></div>
                <p className="be-search-note muted text-xs">{t('booking.search.per_room')}</p>
            </form>

            {firstError && <Alert tone="danger">{firstError}</Alert>}
            {result?.promo && <Alert tone={result.promo.status === 'applied' ? 'success' : 'warn'}>{result.promo.message}</Alert>}

            {!result && !busy && !error && <p className="be-empty"><Icon name="calendar-days" size={20} /> {t('booking.search.start')}</p>}
            {busy && !result && <div className="stack"><div className="skeleton" style={{ height: 180 }} /><div className="skeleton" style={{ height: 180 }} /></div>}

            {result && <>
                <div className="be-summary">
                    <strong>{date(result.check_in)} – {date(result.check_out)}</strong>
                    <span className="muted">{t('booking.search.summary', {
                        nights: tc('booking.search.nights', result.nights),
                        guests: tc('booking.search.guests', result.adults + result.children + result.infants),
                        rooms: tc('booking.search.room_count', result.rooms),
                    })}</span>
                </div>
                {result.room_types.length === 0 && <p className="be-empty"><Icon name="calendar-x" size={20} /> {t('booking.search.none')}</p>}
                <div className="be-results">
                    {result.room_types.map((room) => <RoomCard key={room.id} room={room} result={result} cur={cur} property={property} />)}
                </div>
            </>}
        </BookingShell>
    );
}

function RoomCard({ room, result, cur, property }: { room: RoomResult; result: SearchResult; cur: string; property: BookingProperty }) {
    const [photo, setPhoto] = useState(0);
    const [allAmenities, setAllAmenities] = useState(false);
    const shown = allAmenities ? room.amenities : room.amenities.slice(0, 6);
    const book = (rate: Rate) => bookingUrl(property.code, '/checkout', {
        check_in: result.check_in, check_out: result.check_out, adults: result.adults, children: result.children, infants: result.infants,
        rooms: result.rooms, promo_code: result.promo?.status === 'applied' ? result.promo.code : null, room_type: room.id, rate_plan: rate.rate_plan_id,
    });
    return (
        <article className="be-room card">
            <div className="be-room-media">
                {room.images.length > 0 ? <img src={room.images[photo]} alt={room.name} loading="lazy" /> : <span className="be-noimg"><Icon name="image" size={24} /><br />{t('booking.room.no_image')}</span>}
                {room.images.length > 1 && <div className="be-dots">{room.images.map((_, i) => <button key={i} type="button" aria-label={`${i + 1}`} className={i === photo ? 'on' : undefined} onClick={() => setPhoto(i)} />)}</div>}
            </div>
            <div className="be-room-info">
                <h2>{room.name}</h2>
                <ul className="be-facts">
                    <li><Icon name="users" size={15} /> {t('booking.room.up_to', { count: room.max_occupancy })}</li>
                    {room.size && <li><Icon name="ruler" size={15} /> {room.size}</li>}
                    {room.view && <li><Icon name="mountain" size={15} /> {t('booking.room.view', { view: room.view })}</li>}
                </ul>
                {room.description && <p className="be-desc">{room.description}</p>}
                {room.amenities.length > 0 && <ul className="be-amenities" aria-label={t('booking.room.amenities')}>
                    {shown.map((a) => <li key={a.name}>{a.name}</li>)}
                    {!allAmenities && room.amenities.length > 6 && <li><button type="button" className="link-button" onClick={() => setAllAmenities(true)}>{t('booking.room.more', { count: room.amenities.length - 6 })}</button></li>}
                </ul>}
                {room.left <= 3 && <span className="be-left">{t('booking.room.left', { count: room.left })}</span>}
            </div>
            <div className="be-rates">
                {room.rates.map((rate) => <RateRow key={rate.rate_plan_id} rate={rate} nights={result.nights} cur={cur} href={book(rate)} />)}
            </div>
        </article>
    );
}

/** One rate plan: clear badges (refundable, breakfast, payment), total with taxes, and an expandable price & cancellation breakdown. */
function RateRow({ rate, nights, cur, href }: { rate: Rate; nights: number; cur: string; href: string }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="be-rate">
            <div className="be-rate-name">
                <strong>{rate.name}</strong>
                <div className="be-badges">
                    <span className={rate.refundable ? 'be-badge ok' : 'be-badge warn'}><Icon name={rate.refundable ? 'check-circle' : 'ban'} size={13} /> {t(rate.refundable ? 'booking.rate.refundable' : 'booking.rate.non_refundable')}</span>
                    <span className={rate.breakfast ? 'be-badge ok' : 'be-badge'}><Icon name="coffee" size={13} /> {t(rate.breakfast ? 'booking.rate.breakfast' : 'booking.rate.no_breakfast')}</span>
                    <span className="be-badge"><Icon name={rate.payment_type === 'pay_at_property' ? 'building' : 'credit-card'} size={13} /> {t(`booking.rate.${rate.payment_type}`)}</span>
                    {rate.offers.map((o) => <span key={o.name} className="be-badge deal"><Icon name="badge-percent" size={13} /> {o.name}</span>)}
                </div>
                {rate.refundable && <span className="be-tag ok">{rate.policy}</span>}
                <button type="button" className="link-button be-more" aria-expanded={open} onClick={() => setOpen(!open)}>
                    {t(open ? 'booking.rate.hide_details' : 'booking.rate.price_details')} <Icon name={open ? 'chevron-up' : 'chevron-down'} size={14} />
                </button>
            </div>
            <div className="be-rate-price">
                {Number(rate.grand_before) > Number(rate.grand_total) && <s className="muted num">{money(rate.grand_before, cur)}</s>}
                <strong className="num">{money(rate.grand_total, cur)}</strong>
                <span className="muted text-xs">{t('booking.rate.total_for', { nights: tc('booking.search.nights', nights) })} · {t('booking.rate.taxes_included', { amount: money(rate.taxes, cur) })}</span>
                <a className="btn btn-primary" href={href}>{t('booking.rate.book')}</a>
            </div>
            {open && <div className="be-rate-details">
                <PriceLines rate={rate} cur={cur} />
                <div>
                    <strong className="text-sm">{t('booking.checkout.policy')}</strong>
                    <ul className="be-policy">{rate.policy_lines.map((l) => <li key={l}>{l}</li>)}</ul>
                </div>
            </div>}
        </div>
    );
}

createPage(BookingSearch);
