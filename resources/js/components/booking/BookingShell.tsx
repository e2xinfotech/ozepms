import type { ReactNode } from 'react';
import { Icon } from '@/components/ui';
import { t } from '@/lib/i18n';
import { payload } from '@/lib/page';

export interface BookingProperty {
    code: string; name: string; tagline: string | null; description: string | null; address: string; phone: string | null; email: string | null;
    website: string | null; logo: string | null; stars: number | null; currency: string; check_in_time: string; check_out_time: string; today: string;
    limits: { max_nights: number; max_days_ahead: number; max_rooms: number }; online_payments: boolean; enabled: boolean; intro: string | null; terms: string | null;
}

/** Public booking page frame: hotel header with language choice, content, hotel contact footer. No PMS navigation. */
export function BookingShell({ property, children }: { property: BookingProperty; children: ReactNode }) {
    const locales = payload().shell.locales ?? {};
    const current = document.documentElement.lang.slice(0, 2);
    const switchTo = (code: string) => {
        const url = new URL(window.location.href);
        url.searchParams.set('lang', code);
        window.location.href = url.toString();
    };
    return (
        <div className="be">
            <header className="be-header">
                <div className="be-wrap be-header-row">
                    <a className="be-brand" href={`/book/${property.code}`}>
                        {property.logo ? <img src={property.logo} alt="" className="be-logo" /> : <span className="be-logo be-logo-text" aria-hidden="true">{property.name.slice(0, 1)}</span>}
                        <span>
                            <span className="be-name">{property.name}</span>
                            {property.stars ? <span className="be-stars" aria-label={t('booking.stars', { count: property.stars })}>{'★'.repeat(property.stars)}</span> : null}
                            <span className="be-address">{property.address}</span>
                        </span>
                    </a>
                    <label className="be-lang">
                        <Icon name="globe" size={16} />
                        <select aria-label={t('booking.language')} value={current} onChange={(e) => switchTo(e.target.value)}>
                            {Object.entries(locales).map(([code, name]) => <option key={code} value={code}>{name}</option>)}
                        </select>
                    </label>
                </div>
            </header>
            <main className="be-wrap be-main">{children}</main>
            <footer className="be-footer">
                <div className="be-wrap be-footer-row">
                    <span><Icon name="shield-check" size={16} /> {t('booking.secure')}</span>
                    <span>{[property.phone, property.email].filter(Boolean).join(' · ')}</span>
                </div>
            </footer>
        </div>
    );
}

export function bookingUrl(code: string, path = '', params?: Record<string, string | number | null | undefined>): string {
    const qs = params ? new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => [k, String(v)])).toString() : '';
    return `/book/${code}${path}${qs ? `?${qs}` : ''}`;
}
