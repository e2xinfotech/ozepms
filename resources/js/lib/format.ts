import { locale } from './i18n';

/** All number, money and date formatting goes through here. */

function intlLocale(): string {
    const map: Record<string, string> = { en: 'en-GB', fr: 'fr-FR', it: 'it-IT', de: 'de-DE' };
    return map[locale()] ?? 'en-GB';
}

export function money(amount: number | string | null | undefined, currency: string): string {
    if (amount === null || amount === undefined || amount === '') return '—';
    const value = typeof amount === 'string' ? Number(amount) : amount;
    try {
        const formatted = new Intl.NumberFormat(intlLocale(), {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
        return `${currency} ${formatted}`;
    } catch {
        return `${currency} ${value.toFixed(2)}`;
    }
}

export function number(value: number | null | undefined, digits = 0): string {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat(intlLocale(), { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(value);
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
    return new Intl.DateTimeFormat(intlLocale(), { day: '2-digit', month: 'short', year: 'numeric' }).format(d);
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
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
