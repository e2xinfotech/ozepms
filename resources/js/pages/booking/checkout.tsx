import { useRef, useState } from 'react';
import { Alert, Button, Checkbox, Icon, Input, Select, Textarea, type Option } from '@/components/ui';
import { BookingShell, bookingUrl, type BookingProperty } from '@/components/booking/BookingShell';
import { createPage } from '@/lib/boot';
import { date, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t, tc } from '@/lib/i18n';
import { loadCheckoutScript, type RazorpayCheckout, type RazorpayResult } from '@/lib/razorpay';
import { PriceLines } from '@/components/booking/PriceLines';

interface Rate {
    rate_plan_id: string; name: string; meal_plan: string | null; breakfast: boolean; refundable: boolean; policy: string; payment_type: string;
    price_before: string; room_total: string; discount: string; taxes: string; grand_total: string; pay_now: string;
    tax_lines: { name: string; rate: string | null; amount: string }[]; policy_lines: string[]; offers: { name: string; promo_code: string | null }[];
}
interface Props {
    property: BookingProperty;
    stay: { check_in: string; check_out: string; nights: number; adults: number; children: number; infants: number; rooms: number; currency: string; promo: { code: string; status: string } | null };
    room: { id: string; name: string; images: string[]; max_occupancy: number; size: string | null };
    rate: Rate;
    countries: Option[];
}
interface BookResponse { booking: { ref: string; status: string; due: string }; confirmation_url: string; payment: { id: string; checkout: RazorpayCheckout } | null }

const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/** Public booking engine: guest details, price summary and booking (pay at hotel or online). */
function BookingCheckout({ property, stay, room, rate, countries }: Props) {
    const cur = property.currency;
    const [g, setG] = useState({ first_name: '', last_name: '', email: '', phone: '', nationality_iso2: '' });
    const [extra, setExtra] = useState({ arrival_time: '', special_requests: '', accept_terms: false, website: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const key = useRef(uuid());
    const err = (k: string) => error?.field(k);
    const online = rate.payment_type !== 'pay_at_property';
    const payOnline = online && property.online_payments && Number(rate.pay_now) > 0;
    const backUrl = bookingUrl(property.code, '', { check_in: stay.check_in, check_out: stay.check_out, adults: stay.adults, children: stay.children, infants: stay.infants, rooms: stay.rooms, promo_code: stay.promo?.code });

    const pay = async (res: BookResponse) => {
        const p = res.payment!;
        const Razorpay = await loadCheckoutScript();
        const rz = new Razorpay({
            ...p.checkout,
            handler: async (r: RazorpayResult) => {
                try {
                    const done = await http.post<{ confirmation_url: string }>(bookingUrl(property.code, `/api/payments/${p.id}/verify`), r);
                    window.location.href = done.confirmation_url;
                } catch {
                    window.location.href = res.confirmation_url;
                }
            },
            modal: { ondismiss: () => { window.location.href = res.confirmation_url; } },
        });
        rz.on('payment.failed', () => { window.location.href = res.confirmation_url; });
        rz.open();
    };

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<BookResponse>(bookingUrl(property.code, '/api/book'), {
                check_in: stay.check_in, check_out: stay.check_out, adults: stay.adults, children: stay.children, infants: stay.infants, rooms: stay.rooms,
                promo_code: stay.promo?.code ?? null, room_type_id: room.id, rate_plan_id: rate.rate_plan_id, quoted_total: rate.grand_total,
                idempotency_key: key.current, guest: { ...g, nationality_iso2: g.nationality_iso2 || null },
                arrival_time: extra.arrival_time || null, special_requests: extra.special_requests || null, accept_terms: extra.accept_terms,
                ...(extra.website ? { website: extra.website } : {}),
            });
            if (res.payment) {
                await pay(res);
            } else {
                window.location.href = res.confirmation_url;
            }
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
            setTimeout(() => document.querySelector('.field-error, .alert.danger')?.scrollIntoView({ block: 'center', behavior: 'smooth' }), 50);
        }
    };
    const first = error ? (error.field('quoted_total') ?? error.field('rate_plan_id') ?? error.field('promo_code') ?? (Object.keys(error.fields ?? {}).length ? null : error.message)) : null;

    return (
        <BookingShell property={property}>
            <a className="be-back" href={backUrl}><Icon name="arrow-left" size={16} /> {t('booking.checkout.back')}</a>
            <h1 className="be-title">{t('booking.checkout.title')}</h1>
            <div className="be-checkout">
                <form className="card be-form" onSubmit={(e) => { e.preventDefault(); submit(); }} noValidate>
                    <div className="card-head"><h3>{t('booking.checkout.guest')}</h3></div>
                    <div className="card-body form-grid">
                        <Input fieldClass="span-6" label={t('booking.checkout.first_name')} required autoComplete="given-name" maxLength={80} value={g.first_name} onChange={(e) => setG({ ...g, first_name: e.target.value })} error={err('guest.first_name')} />
                        <Input fieldClass="span-6" label={t('booking.checkout.last_name')} required autoComplete="family-name" maxLength={80} value={g.last_name} onChange={(e) => setG({ ...g, last_name: e.target.value })} error={err('guest.last_name')} />
                        <Input fieldClass="span-6" type="email" label={t('booking.checkout.email')} required autoComplete="email" maxLength={150} value={g.email} onChange={(e) => setG({ ...g, email: e.target.value })} error={err('guest.email')} hint={t('booking.checkout.email_hint')} />
                        <Input fieldClass="span-6" type="tel" label={t('booking.checkout.phone')} required autoComplete="tel" maxLength={30} value={g.phone} onChange={(e) => setG({ ...g, phone: e.target.value })} error={err('guest.phone')} />
                        <Select fieldClass="span-6" label={t('booking.checkout.country')} optional value={g.nationality_iso2} placeholder="—" options={countries} onChange={(e) => setG({ ...g, nationality_iso2: e.target.value })} error={err('guest.nationality_iso2')} />
                        <Input fieldClass="span-6" type="time" label={t('booking.checkout.arrival_time')} optional value={extra.arrival_time} onChange={(e) => setExtra({ ...extra, arrival_time: e.target.value })} error={err('arrival_time')} />
                        <Textarea fieldClass="span-12" label={t('booking.checkout.requests')} optional rows={3} maxLength={1000} value={extra.special_requests} onChange={(e) => setExtra({ ...extra, special_requests: e.target.value })} hint={t('booking.checkout.requests_hint')} />
                        {/* Honeypot: hidden from people, filled only by bots. */}
                        <input className="be-hp" tabIndex={-1} autoComplete="off" aria-hidden="true" name="website" value={extra.website} onChange={(e) => setExtra({ ...extra, website: e.target.value })} />
                        <div className="field span-12">
                            <div className="info-box text-sm">
                                <strong>{t('booking.checkout.policy')}</strong>
                                <ul className="be-policy">{rate.policy_lines.map((l) => <li key={l}>{l}</li>)}</ul>
                                {property.terms && <p className="muted" style={{ marginTop: 6 }}>{property.terms}</p>}
                            </div>
                        </div>
                        <div className="field span-12">
                            <Checkbox checked={extra.accept_terms} onChange={(e) => setExtra({ ...extra, accept_terms: e.target.checked })} label={t('booking.checkout.terms')} />
                            {err('accept_terms') && <div className="field-error">{err('accept_terms')}</div>}
                        </div>
                        {first && <div className="span-12"><Alert tone="danger">{first}</Alert></div>}
                        <div className="span-12 be-submit">
                            <Button type="submit" variant="primary" icon={payOnline ? 'credit-card' : 'check'} loading={busy}>{payOnline ? t('booking.checkout.confirm_pay', { amount: money(rate.pay_now, cur) }) : t('booking.checkout.confirm')}</Button>
                        </div>
                    </div>
                </form>

                <aside className="be-side">
                    <div className="card">
                        {room.images[0] && <img className="be-side-img" src={room.images[0]} alt={room.name} />}
                        <div className="card-body stack">
                            <h3>{room.name}</h3>
                            <div className="be-badges">
                                <span className="be-badge">{rate.name}</span>
                                <span className={rate.refundable ? 'be-badge ok' : 'be-badge warn'}>{t(rate.refundable ? 'booking.rate.refundable' : 'booking.rate.non_refundable')}</span>
                                <span className={rate.breakfast ? 'be-badge ok' : 'be-badge'}>{t(rate.breakfast ? 'booking.rate.breakfast' : 'booking.rate.no_breakfast')}</span>
                            </div>
                            <dl className="be-dl">
                                <dt>{t('booking.search.check_in')}</dt><dd>{date(stay.check_in)} · {property.check_in_time}</dd>
                                <dt>{t('booking.search.check_out')}</dt><dd>{date(stay.check_out)} · {property.check_out_time}</dd>
                                <dt>{t('booking.checkout.stay')}</dt><dd>{tc('booking.search.nights', stay.nights)} · {tc('booking.search.room_count', stay.rooms)} · {tc('booking.search.guests', stay.adults + stay.children + stay.infants)}</dd>
                            </dl>
                            <PriceLines rate={rate} cur={cur} />
                            <div className="info-box text-sm">
                                <strong>{t('booking.checkout.payment')}:</strong> {t(`booking.rate.${rate.payment_type}`)}
                                <div className="muted">{payOnline ? t('booking.checkout.pay_now', { amount: money(rate.pay_now, cur) }) : online ? t('booking.checkout.pending_note') : t('booking.checkout.pay_later')}</div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </BookingShell>
    );
}

createPage(BookingCheckout);
