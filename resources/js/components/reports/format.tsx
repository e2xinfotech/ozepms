import type { ReactNode } from 'react';
import { Badge } from '@/components/ui';
import { date, money, moneyShort, number, percent, shortDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { propertyUrl } from '@/lib/page';
import type { ValueType } from './types';

/** "Oct 2026" in the interface language. */
export function monthLabel(value: string, short = false): string {
    const d = new Date(`${value.slice(0, 7)}-01T00:00:00`);
    if (Number.isNaN(d.getTime())) return value;
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en', { month: short ? 'short' : 'long', year: short ? '2-digit' : 'numeric' }).format(d);
}

export function periodLabel(value: string, group: string, short = false): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    if (group === 'month') return monthLabel(value, short);
    if (group === 'week') return short ? shortDate(value) : t('reports.week_of', { date: date(value) });
    return short ? shortDate(value) : date(value);
}

/** Display of one report value by its type; rows carry the reservation id for booking links. */
export function cell(type: ValueType, value: unknown, row: Record<string, unknown>, currency: string, group: string): ReactNode {
    if (value === null || value === undefined || value === '') return type === 'text' ? '' : '—';
    switch (type) {
        case 'money': return money(value as string, currency);
        case 'percent': return percent(Number(value), 1);
        case 'int': return number(Number(value));
        case 'decimal': return number(Number(value), 1);
        case 'date': return date(String(value));
        case 'period': return periodLabel(String(value), group);
        case 'status': return <Badge status={String(value)}>{t(`reservations.status.${value}`)}</Badge>;
        case 'ref': return row.id ? <a className="id-link" href={propertyUrl(`/reservations/${row.id}`)}>{String(value)}</a> : String(value);
        case 'mixed': return cell((row.type as ValueType) ?? 'text', value, row, currency, group);
        default: return String(value);
    }
}

/** Plain text of a value (KPI tiles, chart axes). */
/** short: 'axis' always uses the short money form, 'tile' from 1,000 up (keeps cents on ADR etc.). */
export function plain(type: ValueType, value: unknown, currency: string, short: false | 'tile' | 'axis' = false): string {
    if (value === null || value === undefined || value === '') return '—';
    switch (type) {
        case 'money': return short === 'axis' || (short === 'tile' && Math.abs(Number(value)) >= 1000) ? moneyShort(value as string, currency) : money(value as string, currency);
        case 'percent': return percent(Number(value), 1);
        case 'int': return number(Number(value));
        case 'decimal': return number(Number(value), 1);
        default: return String(value);
    }
}

export const isNumeric = (type: ValueType) => ['int', 'decimal', 'money', 'percent', 'mixed'].includes(type);
