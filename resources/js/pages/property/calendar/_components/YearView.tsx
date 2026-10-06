import clsx from 'clsx';
import { useRef, useState, type CSSProperties, type MouseEvent } from 'react';
import { Icon } from '@/components/ui';
import { t } from '@/lib/i18n';
import { addDays, fullDay, monthName } from './dates';
import { price } from './CalendarGrid';
import type { YearData, YearRow } from './types';

interface Props {
    year: YearData;
    /** A month or a night was clicked: open the month view there. */
    onOpen: (date: string) => void;
}

const LEVELS: Record<string, string> = { '0': 'sold_out', '1': 'low', '2': 'ok', s: 'stop', '.': 'none' };

function dayTip(row: YearRow, date: string, i: number): string {
    const level = LEVELS[row.levels[i]] ?? 'none';
    const parts = [fullDay(date), t(`calendar.year.levels.${level}`)];
    const p = row.prices[i];
    parts.push(p === null ? t('calendar.no_rate') : t('calendar.year.from_price', { price: price(p) }));
    return parts.join(' · ');
}

/**
 * Twelve months at a glance. One line per room type; each month is a small calendar (weeks × weekdays)
 * coloured by availability, with the month's lowest open price underneath. The server sends one level
 * string and one price list per room type, so the page stays light whatever the number of rate plans.
 * Clicking a month name or a night opens the month view.
 */
export function YearView({ year, onOpen }: Props) {
    const [tip, setTip] = useState<{ text: string; x: number; y: number; below: boolean } | null>(null);
    const wrap = useRef<HTMLDivElement>(null);

    const dateAt = (i: number) => addDays(year.from, i);

    const onOver = (e: MouseEvent) => {
        const el = (e.target as HTMLElement).closest<HTMLElement>('[data-tip]');
        if (el && wrap.current?.contains(el)) {
            const r = el.getBoundingClientRect();
            const below = r.top < 140;
            setTip({ text: el.dataset.tip!, x: r.left + r.width / 2, y: below ? r.bottom + 8 : r.top - 8, below });
        } else setTip(null);
    };

    const onClick = (e: MouseEvent) => {
        const el = (e.target as HTMLElement).closest<HTMLElement>('[data-date]');
        if (el) onOpen(el.dataset.date!);
    };

    const style = { '--year-months': year.months.length } as CSSProperties;

    return (
        <div className="year-wrap" ref={wrap} style={style} onMouseOver={onOver} onMouseLeave={() => setTip(null)} onClick={onClick}>
            <div className="year-row year-head" role="row">
                <div className="year-label"><b>{t('calendar.year.room_types')}</b><small>{t('calendar.year.hint')}</small></div>
                {year.months.map((m) => {
                    const first = `${m.month}-01`;
                    const current = year.today.startsWith(m.month);
                    return (
                        <button key={m.month} type="button" className={clsx('year-month-name', current && 'current')} data-date={first}
                            title={t('calendar.year.open_month')} aria-label={`${monthName(first)} ${m.month.slice(0, 4)} – ${t('calendar.year.open_month')}`}>
                            <b>{monthName(first)}</b><small className="num">{m.month.slice(0, 4)}</small>
                        </button>
                    );
                })}
            </div>

            {year.room_types.map((row) => (
                <div key={row.id} className={clsx('year-row', !row.is_active && 'inactive')} role="row">
                    <div className="year-label">
                        <b title={`${row.name} (${row.code})`}>{row.name} ({row.code})</b>
                        {!row.is_active && <small>{t('calendar.inactive')}</small>}
                    </div>
                    {year.months.map((m, k) => (
                        <div key={m.month} className="year-month">
                            <div className="year-days" style={{ '--offset': m.first_dow - 1 } as CSSProperties}>
                                {Array.from({ length: m.days }, (_, j) => {
                                    const i = m.start + j;
                                    const date = dateAt(i);
                                    const level = LEVELS[row.levels[i]] ?? 'none';
                                    const tipText = dayTip(row, date, i);
                                    return (
                                        <span key={j} className={clsx('year-day', `lv-${level}`, date === year.today && 'today', date < year.today && 'past')}
                                            data-date={date} data-tip={tipText} aria-label={tipText} role="button" tabIndex={-1} />
                                    );
                                })}
                            </div>
                            <small className="year-min num" title={row.month_min[k] === null ? t('calendar.no_rate') : t('calendar.year.month_min', { price: price(row.month_min[k]) })}>
                                {row.month_min[k] === null ? '—' : <>{t('calendar.year.from')} {price(row.month_min[k])}</>}
                            </small>
                        </div>
                    ))}
                </div>
            ))}

            {tip && <div className={clsx('cal-tip', tip.below && 'below')} role="tooltip" style={{ left: tip.x, top: tip.y }}>{tip.text}</div>}
        </div>
    );
}

/** Colour key of the year view. */
export function YearLegend({ currency }: { currency: string }) {
    return (
        <ul className="cal-legend" aria-label={t('calendar.year.legend')}>
            {['sold_out', 'low', 'ok', 'stop', 'none'].map((level) => {
                const label = t(`calendar.year.levels.${level}`);
                return <li key={level} title={label}><i className={`swatch year-sw lv-${level}`} aria-hidden="true" /><span>{label}</span></li>;
            })}
            <li className="sep" aria-hidden="true" />
            <li title={t('calendar.year.price_hint', { currency })}><Icon name="tags" size={16} /><span>{t('calendar.year.price_hint', { currency })}</span></li>
        </ul>
    );
}
