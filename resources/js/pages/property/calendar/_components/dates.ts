import { locale } from '@/lib/i18n';

/** Plain Y-m-d arithmetic in UTC so the browser time zone never shifts a stay date. */
export function addDays(ymd: string, days: number): string {
    const d = new Date(ymd + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}

export function addMonths(ymd: string, months: number): string {
    const d = new Date(ymd.slice(0, 7) + '-01T00:00:00Z');
    d.setUTCMonth(d.getUTCMonth() + months);
    return d.toISOString().slice(0, 10);
}

function intl(): string {
    return ({ en: 'en-GB', fr: 'fr-FR', it: 'it-IT', de: 'de-DE' } as Record<string, string>)[locale()] ?? 'en-GB';
}

/** "March 2026" */
export function monthLabel(ymd: string): string {
    const s = new Intl.DateTimeFormat(intl(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(ymd + 'T00:00:00Z'));
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/** "12 Mar" */
export function dayLabel(ymd: string): string {
    return new Intl.DateTimeFormat(intl(), { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(ymd + 'T00:00:00Z'));
}

/** "12 Mar 2026" */
export function fullDay(ymd: string): string {
    return new Intl.DateTimeFormat(intl(), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(ymd + 'T00:00:00Z'));
}

/** "Mar 2026" */
export function shortMonth(ymd: string): string {
    const s = new Intl.DateTimeFormat(intl(), { month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(ymd + 'T00:00:00Z'));
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/** "Mar" */
export function monthName(ymd: string): string {
    const s = new Intl.DateTimeFormat(intl(), { month: 'short', timeZone: 'UTC' }).format(new Date(ymd + 'T00:00:00Z'));
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/** "Tue, 12 Mar 2026" */
export function longDay(ymd: string): string {
    return new Intl.DateTimeFormat(intl(), { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(ymd + 'T00:00:00Z'));
}

/** Window title: "March 2026", "12 Mar – 25 Mar 2026" (two weeks), "Tue, 12 Mar 2026" (day), "Oct 2026 – Sep 2027" (year). */
export function windowLabel(from: string, to: string, range: 'day' | 'week' | 'month' | 'year'): string {
    if (range === 'month') return monthLabel(from);
    if (range === 'day') return longDay(from);
    if (range === 'year') return `${shortMonth(from)} – ${shortMonth(to)}`;
    return `${dayLabel(from)} – ${fullDay(to)}`;
}
