import { Avatar, Badge, Flag, Icon } from '@/components/ui';
import { date, money } from '@/lib/format';
import { t } from '@/lib/i18n';
import { propertyUrl } from '@/lib/page';
import type { ReservationRow } from './types';

/** Small pieces shared by the reservation pages, the side panel and the front desk. */

export function reservationUrl(id: string, tab?: string): string {
    return propertyUrl(`/reservations/${id}${tab ? `?tab=${tab}` : ''}`);
}

export function StatusBadge({ status, size }: { status: string; size?: 'sm' }) {
    return <Badge size={size} status={status}>{t(`reservations.status.${status}`)}</Badge>;
}

export function PaymentBadge({ status }: { status: string }) {
    return <Badge size="sm" status={status}>{t(`reservations.payment_status.${status}`)}</Badge>;
}

export function GuestCell({ guest }: { guest: ReservationRow['guest'] }) {
    return (
        <span className="guest-cell">
            <Avatar name={guest.name} size="sm" />
            <Flag code={guest.nationality} />
            <span className="cell-main">{guest.name}</span>
            {guest.vip && <span title="VIP"><Icon name="star" size={14} className="star-fill" /></span>}
        </span>
    );
}

/** "2 + 1" adults + children (infants in the tooltip). */
export function GuestCount({ adults, children, infants = 0 }: { adults: number; children: number; infants?: number }) {
    const title = [t('reservations.adults_count', { count: adults }), t('reservations.children_count', { count: children }), infants ? t('reservations.infants_count', { count: infants }) : '']
        .filter(Boolean).join(', ');
    return <span className="num" title={title}>{t('reservations.guests_short', { adults, children })}</span>;
}

/** A date that has passed while the guest has not arrived / left yet is shown in red. */
export function StayDate({ value, late, time }: { value: string; late?: boolean; time?: string | null }) {
    return (
        <span className={late ? 'text-danger' : undefined} title={late ? t('reservations.overdue_arrival') : undefined}>
            {date(value)}{time && <span className="cell-sub">{time}</span>}
        </span>
    );
}

export function Money({ value, currency, strong }: { value: string | number | null | undefined; currency: string; strong?: boolean }) {
    return <span className={strong ? 'num strong' : 'num'}>{money(value, currency)}</span>;
}

export function roomTypeLabel(r: ReservationRow): string {
    const first = r.room_type[0];
    if (!first) return '—';
    const extra = r.room_type.length > 1 ? ` ${t('reservations.multiple_rooms', { count: r.room_type.length - 1 })}` : '';
    return `${first.name} (${first.code})${extra}`;
}

export function unitsLabel(r: ReservationRow): string {
    return r.units.length > 0 ? r.units.join(', ') : '—';
}

export function sourceLabel(r: ReservationRow): string {
    return r.source?.name ?? '—';
}
