import { locale } from './i18n';
import { payload } from './page';

/**
 * All number, money and date formatting goes through here. Inside a property the
 * property's regional settings (Property Configuration → Regional) are used:
 * number format (a locale such as en-IN), date format (e.g. DD/MM/YYYY) and first day of week.
 */

function intlLocale(): string {
    const map: Record<string, string> = { en: 'en-GB', fr: 'fr-FR', it: 'it-IT', de: 'de-DE' };
    return map[locale()] ?? 'en-GB';
}

function regional(): { date_format?: string | null; number_format?: string | null; week_start?: number } {
    try {
        return payload().shell?.property ?? {};
    } catch {
        return {};
    }
}

/** Locale for digits and separators: the property's number format, else the interface language. */
function numberLocale(): string {
    return regional().number_format || intlLocale();
}

/** First day of the week of the current property (0 = Sunday, 1 = Monday, 6 = Saturday). */
export function weekStart(): number {
    return regional().week_start ?? 1;
}

/** Formats a date with a pattern such as "DD MMM YYYY", "DD/MM/YYYY", "MM/DD/YYYY" or "YYYY-MM-DD". */
export function formatPattern(d: Date, pattern: string): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    const month = new Intl.DateTimeFormat(intlLocale(), { month: 'short' }).format(d);
    return pattern
        .replace('YYYY', String(d.getFullYear()))
        .replace('MMM', month)
        .replace('MM', pad(d.getMonth() + 1))
        .replace('DD', pad(d.getDate()));
}

export function money(amount: number | string | null | undefined, currency: string): string {
    if (amount === null || amount === undefined || amount === '') return '—';
    const value = typeof amount === 'string' ? Number(amount) : amount;
    try {
        const formatted = new Intl.NumberFormat(numberLocale(), {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
        return `${currency} ${formatted}`;
    } catch {
        return `${currency} ${value.toFixed(2)}`;
    }
}

/** Money for narrow KPI tiles: whole units, and a short form (e.g. 2.2L / 224K / 1.5M) from 100,000 up. */
export function moneyShort(amount: number | string | null | undefined, currency: string): string {
    if (amount === null || amount === undefined || amount === '') return '—';
    const value = typeof amount === 'string' ? Number(amount) : amount;
    try {
        const big = Math.abs(value) >= 100000;
        const formatted = new Intl.NumberFormat(numberLocale(), big ? { notation: 'compact', maximumFractionDigits: 1 } : { maximumFractionDigits: 0 }).format(value);
        return `${currency} ${formatted}`;
    } catch {
        return money(amount, currency);
    }
}

export function number(value: number | null | undefined, digits = 0): string {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat(numberLocale(), { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(value);
}

export function percent(value: number | null | undefined, digits = 0): string {
    if (value === null || value === undefined) return '—';
    return `${number(value, digits)}%`;
}

/** "04 Oct 2026" — accepts ISO date or datetime strings. Date-only values are not timezone-shifted. */
export function date(value: string | null | undefined): string {
    if (!value) return '—';
    const d = value.length === 10 ? new Date(`${value}T00:00:00`) : new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    const pattern = regional().date_format;
    if (pattern) return formatPattern(d, pattern);
    return new Intl.DateTimeFormat(intlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }).format(d);
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    const pattern = regional().date_format;
    if (pattern) return `${formatPattern(d, pattern)}, ${new Intl.DateTimeFormat(intlLocale(), { hour: '2-digit', minute: '2-digit' }).format(d)}`;
    return new Intl.DateTimeFormat(intlLocale(), {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(d);
}

export function time(value: string | null | undefined): string {
    if (!value) return '—';
    const [h, m] = value.split(':').map(Number);
    const d = new Date();
    d.setHours(h ?? 0, m ?? 0, 0, 0);
    return new Intl.DateTimeFormat(intlLocale(), { hour: '2-digit', minute: '2-digit' }).format(d);
}

/** "2 mins ago", "3 hours ago" */
export function relative(value: string | null | undefined): string {
    if (!value) return '—';
    const diff = (new Date(value).getTime() - Date.now()) / 1000;
    const rtf = new Intl.RelativeTimeFormat(intlLocale(), { numeric: 'auto' });
    const steps: [number, Intl.RelativeTimeFormatUnit][] = [[60, 'second'], [60, 'minute'], [24, 'hour'], [30, 'day'], [12, 'month'], [Infinity, 'year']];
    let amount = diff;
    for (const [size, unit] of steps) {
        if (Math.abs(amount) < size) return rtf.format(Math.round(amount), unit);
        amount /= size;
    }
    return date(value);
}

/** Chart axis label: "01 Oct" in the interface language. */
export function shortDate(value: string): string {
    const d = new Date(`${value.slice(0, 10)}T00:00:00`);
    if (Number.isNaN(d.getTime())) return value;
    return new Intl.DateTimeFormat(intlLocale(), { day: '2-digit', month: 'short' }).format(d);
}
