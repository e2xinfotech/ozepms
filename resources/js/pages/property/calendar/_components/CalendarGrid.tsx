import clsx from 'clsx';
import { memo, useCallback, useEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type MouseEvent } from 'react';
import { Badge, Icon } from '@/components/ui';
import { number } from '@/lib/format';
import { t } from '@/lib/i18n';
import { barToneOf } from '@/lib/status';
import { fullDay, windowLabel } from './dates';
import type { Bar, Day, Grid, InvDay, ProductRow, RateDay, RoomTypeRow, Selection, UnitRow, View } from './types';

interface Props {
    grid: Grid;
    view: View;
    range: 'month' | 'week';
    canEdit: boolean;
    selection: Selection | null;
    onSelect: (sel: Selection | null) => void;
    /** Selection finished (mouse released or Enter): open the editor near this point. */
    onEdit: (sel: Selection, at: { x: number; y: number }) => void;
}

/** Shows a number as on the design: no decimals unless the price has cents. */
export function price(value: string | null): string {
    if (value === null) return '—';
    const n = Number(value);
    return number(n, Number.isInteger(n) ? 0 : 2);
}

function invTip(d: InvDay): string {
    const parts = [d.a === 0 ? t('calendar.tips.sold_out') : t('calendar.tips.available', { available: d.a, total: d.t })];
    if (d.ss) parts.push(t('calendar.tips.stop_sell'));
    if (d.s) parts.push(t('calendar.tips.sold', { count: d.s }));
    if (d.h) parts.push(t('calendar.tips.held', { count: d.h }));
    if (d.o) parts.push(t('calendar.tips.ooo', { count: d.o }));
    if (d.l !== null) parts.push(t('calendar.tips.sell_limit', { count: d.l }));
    if (d.d) parts.push(t('calendar.default_value'));
    return parts.join(' · ');
}

function rateTip(d: RateDay, p: ProductRow): string {
    const parts = [d.p === null ? t('calendar.no_rate') : t('calendar.tips.price', { price: price(d.p) })];
    if (p.pricing_mode === 'derived' && p.parent) parts.push(t('calendar.derived', { parent: p.parent }));
    if (d.o) for (const [adults, value] of Object.entries(d.o)) parts.push(t('calendar.tips.occupancy', { count: adults, price: price(value) }));
    parts.push(d.max ? t('calendar.tips.los', { min: d.min, max: d.max }) : t('calendar.tips.los_open', { min: d.min }));
    if (d.mla) parts.push(t('calendar.tips.min_los_arrival', { count: d.mla }));
    if (d.cut) parts.push(t('calendar.tips.cutoff', { count: d.cut }));
    if (d.adv) parts.push(t('calendar.tips.max_advance', { count: d.adv }));
    if (d.ss) parts.push(t('calendar.tips.closed'));
    if (d.cta) parts.push(t('calendar.tips.cta'));
    if (d.ctd) parts.push(t('calendar.tips.ctd'));
    if (d.d) parts.push(t('calendar.default_value'));
    return parts.join(' · ');
}

function barTip(b: Bar): string {
    const dates = t('calendar.bar.dates', { from: fullDay(b.check_in), to: fullDay(b.check_out) });
    if (b.kind === 'block') {
        return [t(`rooms.block_types.${b.block_type}`), dates, b.reason].filter(Boolean).join(' · ');
    }
    return [`${b.guest} · ${b.reference}`, t(`ui.status.${b.status}`), dates, t('calendar.bar.guests', { adults: b.adults ?? 0, children: b.children ?? 0 })].join(' · ');
}

const inSel = (sel: Selection | null, rowId: string, i: number) => !!sel && sel.rowId === rowId && i >= Math.min(sel.start, sel.end) && i <= Math.max(sel.start, sel.end);

/**
 * The calendar body. One CSS grid row per line (label column + one column per night),
 * sticky date header and label column, pointer events delegated to the container so a
 * month of a large property stays light. Collapsed room types render one line only.
 */
export function CalendarGrid({ grid, view, range, canEdit, selection, onSelect, onEdit }: Props) {
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [roomsOpen, setRoomsOpen] = useState<Set<string>>(new Set());
    const [tip, setTip] = useState<{ text: string; x: number; y: number; below: boolean } | null>(null);
    const drag = useRef<{ sel: Selection } | null>(null);
    const wrap = useRef<HTMLDivElement>(null);

    const toggle = useCallback((set: 'collapsed' | 'rooms', id: string) => {
        const update = set === 'collapsed' ? setCollapsed : setRoomsOpen;
        update((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }, []);

    const cellFrom = (target: EventTarget | null): Selection | null => {
        const el = (target as HTMLElement | null)?.closest<HTMLElement>('[data-row]');
        if (!el) return null;
        const i = Number(el.dataset.i);
        return { kind: el.dataset.kind as Selection['kind'], rowId: el.dataset.row!, roomTypeId: el.dataset.rt!, start: i, end: i };
    };

    const onMouseDown = (e: MouseEvent) => {
        if (!canEdit || e.button !== 0) return;
        const sel = cellFrom(e.target);
        if (!sel) return;
        e.preventDefault();
        drag.current = { sel };
        onSelect(sel);
    };

    const onMouseOver = (e: MouseEvent) => {
        const el = (e.target as HTMLElement).closest<HTMLElement>('[data-tip]');
        if (el && wrap.current?.contains(el)) {
            const r = el.getBoundingClientRect();
            const below = r.top < 140;
            setTip({ text: el.dataset.tip!, x: r.left + r.width / 2, y: below ? r.bottom + 8 : r.top - 8, below });
        } else {
            setTip(null);
        }
        if (drag.current) {
            const over = cellFrom(e.target);
            if (over && over.rowId === drag.current.sel.rowId && over.end !== drag.current.sel.end) {
                drag.current.sel = { ...drag.current.sel, end: over.end };
                onSelect(drag.current.sel);
            }
        }
    };

    useEffect(() => {
        const up = (e: globalThis.MouseEvent) => {
            if (!drag.current) return;
            const { sel } = drag.current;
            drag.current = null;
            const ordered = { ...sel, start: Math.min(sel.start, sel.end), end: Math.max(sel.start, sel.end) };
            onSelect(ordered);
            onEdit(ordered, { x: e.clientX, y: e.clientY });
        };
        window.addEventListener('mouseup', up);
        return () => window.removeEventListener('mouseup', up);
    }, [onEdit, onSelect]);

    const onKeyDown = (e: KeyboardEvent) => {
        if (!canEdit || (e.key !== 'Enter' && e.key !== ' ')) return;
        const sel = cellFrom(e.target);
        if (!sel) return;
        e.preventDefault();
        const r = (e.target as HTMLElement).getBoundingClientRect();
        onSelect(sel);
        onEdit(sel, { x: r.left + r.width / 2, y: r.bottom });
    };

    const style = { '--cal-days': grid.days.length } as CSSProperties;

    return (
        <div className={clsx('cal-wrap', `view-${view}`)} ref={wrap} style={style}
            onMouseDown={onMouseDown} onMouseOver={onMouseOver} onMouseLeave={() => setTip(null)} onKeyDown={onKeyDown}
            role="grid" aria-readonly={!canEdit} aria-label={t('calendar.title')}>
            <div className="cal-row cal-head" role="row">
                <div className="cal-label" role="columnheader">
                    <b>{windowLabel(grid.from, grid.to, range)}</b>
                    <small title={t('calendar.header')}>{t('calendar.header')}</small>
                </div>
                {grid.days.map((d) => (
                    <div key={d.date} role="columnheader" className={clsx('cal-day', d.weekend && 'weekend', d.date === grid.today && 'today')} title={fullDay(d.date)}>
                        <b className="num">{d.day}</b><span>{t(`calendar.weekday_short.${d.dow}`)}</span>
                    </div>
                ))}
            </div>

            {grid.room_types.map((rt) => (
                <RoomTypeGroup key={rt.id} rt={rt} days={grid.days} view={view} canEdit={canEdit}
                    collapsed={collapsed.has(rt.id)} roomsOpen={view === 'reservations' || roomsOpen.has(rt.id)}
                    selection={selection?.roomTypeId === rt.id ? selection : null} onToggle={toggle} />
            ))}

            {tip && (
                <div className={clsx('cal-tip', tip.below && 'below')} role="tooltip" style={{ left: tip.x, top: tip.y }}>{tip.text}</div>
            )}
        </div>
    );
}

interface GroupProps {
    rt: RoomTypeRow;
    days: Day[];
    view: View;
    canEdit: boolean;
    collapsed: boolean;
    roomsOpen: boolean;
    selection: Selection | null;
    onToggle: (set: 'collapsed' | 'rooms', id: string) => void;
}

const RoomTypeGroup = memo(function RoomTypeGroup({ rt, days, view, canEdit, collapsed, roomsOpen, selection, onToggle }: GroupProps) {
    const summary = rt.units_count === 0 ? t('calendar.rooms_none')
        : rt.units_count === 1 ? t('calendar.rooms_one', { range: rt.units_range ?? '' }) : t('calendar.rooms_many', { count: rt.units_count, range: rt.units_range ?? '' });
    const editable = canEdit ? 0 : undefined;

    return (
        <div className={clsx('cal-group', collapsed && 'collapsed', !rt.is_active && 'inactive')} role="rowgroup">
            <div className="cal-row cal-rt" role="row">
                <div className="cal-label">
                    <button type="button" className="cal-toggle" aria-expanded={!collapsed} onClick={() => onToggle('collapsed', rt.id)}
                        title={collapsed ? t('ui.show') : t('ui.close')} aria-label={`${rt.name} (${rt.code})`}>
                        <Icon name={collapsed ? 'chevron-down' : 'chevron-up'} size={16} />
                    </button>
                    {rt.image ? <img className="cal-thumb" src={rt.image} alt="" loading="lazy" /> : <span className="cal-thumb"><Icon name="bed-double" size={18} /></span>}
                    <span className="cal-rt-name">
                        <b title={`${rt.name} (${rt.code})`}>{rt.name} ({rt.code})</b>
                        <small title={summary}>{summary}{!rt.is_active && <> · {t('calendar.inactive')}</>}</small>
                    </span>
                </div>
                {rt.inventory.map((d, i) => (
                    <div key={i} role="gridcell" tabIndex={editable}
                        className={clsx('cal-cell inv', days[i].weekend && 'weekend', d.a === 0 && 'zero', d.ss && 'stop', d.d && 'muted', inSel(selection, rt.id, i) && 'selected')}
                        data-row={rt.id} data-rt={rt.id} data-kind="room_type" data-i={i} data-tip={invTip(d)} aria-label={invTip(d)}>
                        <span className="num">{d.a}</span>
                    </div>
                ))}
            </div>

            {!collapsed && view === 'inventory' && rt.products.map((p) => (
                <ProductLine key={p.id} rt={rt} p={p} days={days} canEdit={canEdit} selection={selection} />
            ))}

            {!collapsed && rt.units.length > 0 && (
                <>
                    {view === 'inventory' && (
                        <div className="cal-row cal-sub" role="row">
                            <div className="cal-label">
                                <button type="button" className="cal-sub-toggle" aria-expanded={roomsOpen} onClick={() => onToggle('rooms', rt.id)}>
                                    <Icon name={roomsOpen ? 'chevron-up' : 'chevron-down'} size={14} />
                                    {t('calendar.pms_rooms')} <span className="muted num">({rt.units.length})</span>
                                </button>
                            </div>
                            {days.map((d) => <div key={d.date} className={clsx('cal-cell blank', d.weekend && 'weekend')} />)}
                        </div>
                    )}
                    {roomsOpen && rt.units.map((u) => <UnitLine key={u.id} u={u} days={days} />)}
                </>
            )}
        </div>
    );
});

function ProductLine({ rt, p, days, canEdit, selection }: { rt: RoomTypeRow; p: ProductRow; days: Day[]; canEdit: boolean; selection: Selection | null }) {
    const derived = p.pricing_mode === 'derived';
    return (
        <div className={clsx('cal-row cal-product', !p.is_active && 'inactive')} role="row">
            <div className="cal-label">
                <span className="cal-plan">
                    <b title={`${p.rate_plan.name} (${p.rate_plan.code})`}>{p.rate_plan.name} ({p.rate_plan.code})</b>
                    <small>
                        <span title={t('calendar.adults', { count: p.base_adults })} aria-label={t('calendar.adults', { count: p.base_adults })}><Icon name="users" size={13} /> {p.base_adults}</span>
                        {p.meal_plan && <span title={t('calendar.meal_plan', { name: p.meal_plan })}><Icon name="coffee" size={13} /> {p.meal_plan}</span>}
                        {derived && <span title={t('calendar.derived', { parent: p.parent ?? '' })}><Icon name="link" size={13} /> {t('calendar.derived_short')}</span>}
                        {!p.is_active && <span>{t('calendar.inactive')}</span>}
                    </small>
                </span>
            </div>
            {p.days.map((d, i) => {
                const tip = rateTip(d, p);
                return (
                    <div key={i} role="gridcell" tabIndex={canEdit ? 0 : undefined}
                        className={clsx('cal-cell rate', days[i].weekend && 'weekend', d.ss && 'stop', inSel(selection, p.id, i) && 'selected')}
                        data-row={p.id} data-rt={rt.id} data-kind="product" data-i={i} data-tip={tip} aria-label={tip}>
                        <span className={clsx('price num', (derived || d.d) && 'muted')}>{price(d.p)}</span>
                        <span className="los num">
                            {d.cta || d.ctd ? (
                                <>
                                    {d.cta && <Icon name="door-closed" size={13} className="mark" title={t('calendar.tips.cta')} />}
                                    {d.ctd && <Icon name="log-out" size={13} className="mark" title={t('calendar.tips.ctd')} />}
                                </>
                            ) : d.ss ? '–' : `${d.min}-${d.max ?? '∞'}`}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

function UnitLine({ u, days }: { u: UnitRow; days: Day[] }) {
    return (
        <div className={clsx('cal-row cal-unit', !u.is_active && 'inactive')} role="row">
            <div className="cal-label">
                <span className="cal-unit-name num" title={u.floor ? `${u.name} · ${u.floor}` : u.name}>{u.name}</span>
                <Badge size="sm" status={u.status}>{t(`ui.status.${u.status}`)}</Badge>
            </div>
            {/* Each night is pinned to its column so the bars (placed explicitly) never push cells along. */}
            {days.map((d, i) => <div key={d.date} className={clsx('cal-cell blank', d.weekend && 'weekend')} style={{ gridColumn: i + 2 }} />)}
            {u.bars.map((b, i) => {
                const tip = barTip(b);
                const tone = barToneOf(b.status);
                const body = (
                    <>
                        <Icon name={b.kind === 'block' ? 'wrench' : 'user-round'} size={14} />
                        <span className="cal-bar-text">
                            {b.kind === 'block' ? <b>{t(`rooms.block_types.${b.block_type}`)}</b> : <><b>{b.guest}</b> <span className="num">{b.reference}</span></>}
                        </span>
                        {b.url && <Icon name="chevron-right" size={14} />}
                    </>
                );
                const props = {
                    className: clsx('cal-bar', `tone-${tone}`, b.cont_before && 'cont-before', b.cont_after && 'cont-after'),
                    style: { gridColumn: `${b.start + 2} / ${b.end + 3}` },
                    'data-tip': tip,
                    title: tip,
                    'aria-label': tip,
                };
                return b.url
                    ? <a key={i} href={b.url} {...props}>{body}</a>
                    : <span key={i} {...props}>{body}</span>;
            })}
        </div>
    );
}
