import { useState } from 'react';
import { Alert, Button, Icon } from '@/components/ui';
import { BookingShell, bookingUrl, type BookingProperty } from '@/components/booking/BookingShell';
import { createPage } from '@/lib/boot';
import { date, dateTime, money } from '@/lib/format';
import { http } from '@/lib/http';
import { t, tc } from '@/lib/i18n';
import { loadCheckoutScript, type RazorpayCheckout, type RazorpayResult } from '@/lib/razorpay';

interface Booking {
    ref: string; status: string; guest: string; check_in: string; check_out: string; nights: number;
    rooms: { room_type: string; rate_plan: string; adults: number; children: number; infants: number; status: string; grand_total: string }[];
    offers: { name: string; amount: string }[]; room_total: string; tax_total: string; grand_total: string; paid: string; currency: string;
    special_requests: string | null; hold_expires_at: string | null; pending_payment: { id: string; amount: string } | null;
}
interface Props { property: BookingProperty; booking: Booking }

/** Public booking engine: confirmation (signed link, also sent by e-mail). */
function BookingConfirmation({ property, booking: b }: Props) {
    const cur = b.currency;
    const [paying, setPaying] = useState(false);
    const waitingPayment = b.status === 'pending' && b.pending_payment !== null && b.hold_expires_at !== null && new Date(b.hold_expires_at) > new Date();
    const balance = Math.max(0, Number(b.grand_total) - Number(b.paid));
    const heading = b.status === 'confirmed' || b.status === 'checked_in' || b.status === 'checked_out' ? 'confirmed'
        : b.status === 'cancelled' ? 'cancelled' : waitingPayment ? 'pending_payment' : 'pending';

    // Retry the online payment while the rooms are still held.
    const retry = async () => {
        if (!b.pending_payment) return;
        setPaying(true);
        try {
            const res = await http.post<{ payment: { id: string; checkout: RazorpayCheckout } }>(bookingUrl(property.code, `/api/payments/${b.pending_payment.id}/checkout`));
            const Razorpay = await loadCheckoutScript();
            const rz = new Razorpay({
                ...res.payment.checkout,
                handler: async (r: RazorpayResult) => {
                    await http.post(bookingUrl(property.code, `/api/payments/${res.payment.id}/verify`), r).catch(() => undefined);
                    window.location.reload();
                },
                modal: { ondismiss: () => setPaying(false) },
            });
            rz.open();
        } catch {
            window.location.reload();
        }
    };

    return (
        <BookingShell property={property}>
            <section className={`be-confirm card tone-${heading}`}>
                <span className="be-confirm-icon"><Icon name={heading === 'confirmed' ? 'check-circle' : heading === 'cancelled' ? 'x-circle' : 'clock'} size={34} /></span>
                <h1>{t(`booking.confirmation.${heading}`)}</h1>
                {heading === 'confirmed' && <p>{t('booking.confirmation.thanks', { name: b.guest })}</p>}
                {heading === 'pending' && <p>{t('booking.confirmation.pending_text')}</p>}
                {heading === 'pending_payment' && <p>{t('booking.confirmation.hold_text', { time: dateTime(b.hold_expires_at) })}</p>}
                <div className="be-ref"><span>{t('booking.confirmation.ref')}</span><strong>{b.ref}</strong></div>
            </section>

            <div className="be-checkout">
                <div className="card">
                    <div className="card-head"><h3>{t('booking.confirmation.stay')}</h3><Button variant="ghost" icon="printer" onClick={() => window.print()}>{t('booking.confirmation.print')}</Button></div>
                    <div className="card-body stack">
                        <dl className="be-dl">
                            <dt>{t('booking.search.check_in')}</dt><dd>{date(b.check_in)} · {property.check_in_time}</dd>
                            <dt>{t('booking.search.check_out')}</dt><dd>{date(b.check_out)} · {property.check_out_time}</dd>
                            <dt>{t('booking.checkout.stay')}</dt><dd>{tc('booking.search.nights', b.nights)}</dd>
                        </dl>
                        <h4>{t('booking.confirmation.rooms')}</h4>
                        <ul className="be-room-list">
                            {b.rooms.map((r, i) => <li key={i}><strong>{r.room_type}</strong> · {r.rate_plan}<br /><span className="muted text-sm">{t('booking.confirmation.guests_line', { adults: r.adults, children: r.children + r.infants })}</span></li>)}
                        </ul>
                        {b.special_requests && <div className="info-box text-sm"><strong>{t('booking.checkout.requests')}:</strong> {b.special_requests}</div>}
                    </div>
                </div>
                <aside className="be-side">
                    <div className="card"><div className="card-body stack">
                        <div className="price-summary">
                            <div className="ps-row"><span>{t('booking.checkout.room_charges')}</span><span className="num">{money(Number(b.room_total) + b.offers.reduce((a, o) => a + Number(o.amount), 0), cur)}</span></div>
                            {b.offers.map((o) => <div key={o.name} className="ps-row"><span>{o.name}</span><span className="num">− {money(o.amount, cur)}</span></div>)}
                            <div className="ps-row"><span>{t('booking.checkout.taxes')}</span><span className="num">{money(b.tax_total, cur)}</span></div>
                            <div className="ps-row total"><span>{t('booking.checkout.total')}</span><span className="num strong">{money(b.grand_total, cur)}</span></div>
                            {Number(b.paid) > 0 && <div className="ps-row"><span>{t('booking.confirmation.paid')}</span><span className="num">{money(b.paid, cur)}</span></div>}
                            {b.status !== 'cancelled' && balance > 0 && <div className="ps-row strong"><span>{t('booking.confirmation.balance')}</span><span className="num">{money(balance, cur)}</span></div>}
                        </div>
                        {waitingPayment && b.pending_payment && <Alert tone="warn">{t('booking.checkout.payment_cancelled')}</Alert>}
                        {waitingPayment && b.pending_payment && <Button variant="primary" icon="credit-card" loading={paying} onClick={retry}>{t('booking.confirmation.pay', { amount: money(b.pending_payment.amount, cur) })}</Button>}
                        <a className="btn btn-outline" href={bookingUrl(property.code)}>{t('booking.confirmation.book_again')}</a>
                        {(property.phone || property.email) && <p className="muted text-sm">{t('booking.confirmation.contact', { hotel: property.name })}: {[property.phone, property.email].filter(Boolean).join(' · ')}</p>}
                    </div></div>
                </aside>
            </div>
        </BookingShell>
    );
}

createPage(BookingConfirmation);
