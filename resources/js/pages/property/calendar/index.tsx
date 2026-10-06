import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, EmptyState, Icon, Input, LinkButton, Select, toast } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { ApiError, http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { BulkUpdateDrawer } from './_components/BulkUpdateDrawer';
import { CalendarGrid } from './_components/CalendarGrid';
import { CellEditor, type SaveResult } from './_components/CellEditor';
import { CopyDialog } from './_components/CopyDialog';
import { addDays, addMonths, windowLabel } from './_components/dates';
import { Legend } from './_components/Legend';
import { YearLegend, YearView } from './_components/YearView';
import type { Filters, Grid, Option, Range, Selection, View, YearData } from './_components/types';

interface Props {
    grid: Grid | null;
    year: YearData | null;
    filters: Filters;
    options: {
        room_types: (Option & { code: string })[];
        rate_plans: Option[];
        units: (Option & { group: string | null })[];
        statuses: Option[];
        availability: Option[];
        restrictions: Option[];
    };
    can: { update: boolean; reservations: boolean };
}

const QUERY_KEYS: (keyof Filters)[] = ['view', 'range', 'from', 'room_type', 'unit', 'rate_plan', 'status', 'availability', 'restriction', 'price_min', 'price_max'];
const RANGES: Range[] = ['day', 'week', 'month', 'year'];
const EXTRA: (keyof Filters)[] = ['availability', 'restriction', 'price_min', 'price_max'];

function filtersFromUrl(fallback: Filters): Filters {
    const q = new URL(window.location.href).searchParams;
    const get = (k: string) => q.get(k) || null;
    const range = get('range');
    return {
        view: get('view') === 'reservations' ? 'reservations' : 'inventory',
        range: RANGES.includes(range as Range) ? (range as Range) : 'month',
        from: get('from') ?? fallback.from,
        room_type: get('room_type'), unit: get('unit'), rate_plan: get('rate_plan'), status: get('status'),
        availability: get('availability'), restriction: get('restriction'), price_min: get('price_min'), price_max: get('price_max'),
    };
}

/**
 * Inventory, rates & restrictions calendar (designs: calendar-ari, calendar-view-toggle, calendar-reservation-bars).
 * Views: day, two weeks, month (grid) and year (overview). The first window comes with the page; every
 * navigation or filter change loads exactly one window (/calendar/grid) or one year overview
 * (/calendar/year) and keeps the query string in step, so Back, Refresh and bookmarks work.
 */
function CalendarPage({ grid: initialGrid, year: initialYear, filters: initialFilters, options, can }: Props) {
    const [grid, setGrid] = useState<Grid | null>(initialGrid);
    const [year, setYear] = useState<YearData | null>(initialYear);
    const [filters, setFilters] = useState<Filters>(initialFilters);
    const [loading, setLoading] = useState(false);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [editing, setEditing] = useState<{ sel: Selection; at: { x: number; y: number } } | null>(null);
    const [bulk, setBulk] = useState(false);
    const [copy, setCopy] = useState(false);
    const [more, setMore] = useState(() => EXTRA.some((k) => !!initialFilters[k]));
    const [price, setPrice] = useState({ min: initialFilters.price_min ?? '', max: initialFilters.price_max ?? '' });
    const seq = useRef(0);

    const load = useCallback(async (next: Filters, push = true) => {
        setFilters(next);
        setSelection(null);
        setEditing(null);
        setPrice({ min: next.price_min ?? '', max: next.price_max ?? '' });
        if (push) {
            const url = new URL(window.location.href);
            for (const key of QUERY_KEYS) {
                const value = next[key];
                const isDefault = (key === 'view' && value === 'inventory') || (key === 'range' && value === 'month');
                if (value && !isDefault) url.searchParams.set(key, String(value)); else url.searchParams.delete(key);
            }
            window.history.pushState(null, '', url.toString());
        }
        const id = ++seq.current;
        setLoading(true);
        const query = {
            from: next.from, room_type: next.room_type, unit: next.unit, rate_plan: next.rate_plan, status: next.status,
            availability: next.availability, restriction: next.restriction, price_min: next.price_min, price_max: next.price_max,
        };
        try {
            if (next.range === 'year') {
                const res = await http.get<{ year: YearData }>(propertyApiUrl('/calendar/year'), query);
                if (id === seq.current) setYear(res.year);
            } else {
                const res = await http.get<{ grid: Grid }>(propertyApiUrl('/calendar/grid'), { ...query, range: next.range });
                if (id === seq.current) setGrid(res.grid);
            }
        } catch (e) {
            if (id === seq.current) toast.error(e instanceof ApiError ? e.message : t('calendar.messages.load_failed'), e instanceof ApiError ? e.ref : undefined);
        } finally {
            if (id === seq.current) setLoading(false);
        }
    }, []);

    useEffect(() => {
        const pop = () => void load(filtersFromUrl(initialFilters), false);
        window.addEventListener('popstate', pop);
        return () => window.removeEventListener('popstate', pop);
    }, [load, initialFilters]);

    const isYear = filters.range === 'year';
    // The window on screen (grid or year overview); falls back to the requested start while loading.
    const shown = isYear ? year : grid;
    const win = { from: shown?.from ?? filters.from, to: shown?.to ?? filters.from, today: shown?.today ?? (grid ?? year)?.today ?? filters.from };

    const change = (patch: Partial<Filters>) => void load({ ...filters, ...patch });
    const step = (dir: -1 | 1) => {
        const from = { day: addDays(win.from, dir), week: addDays(win.from, dir * 14), month: addMonths(win.from, dir), year: addMonths(win.from, dir * 12) }[filters.range];
        change({ from });
    };
    const goToday = () => change({ from: filters.range === 'month' || isYear ? win.today.slice(0, 7) + '-01' : win.today });
    const setRange = (range: Range) => {
        const todayInView = win.from <= win.today && win.today <= win.to;
        const from = range === 'month' || range === 'year' ? (todayInView ? win.today : win.from).slice(0, 7) + '-01' : (todayInView ? win.today : win.from);
        change({ range, from });
    };
    const applyPrice = () => {
        const min = price.min.trim() || null;
        const max = price.max.trim() || null;
        if (min !== filters.price_min || max !== filters.price_max) change({ price_min: min, price_max: max });
    };

    const onSaved = (res: SaveResult) => {
        setEditing(null);
        setBulk(false);
        setCopy(false);
        if (res.result.changed === 0 && res.result.skipped.length === 0) toast.info(t('calendar.messages.nothing_changed'));
        else toast.success(res.message);
        if (res.result.skipped.length) {
            toast.info(t('calendar.messages.skipped', { reasons: res.result.skipped.map((s) => `${s.label} (${s.dates})`).join(', ') }));
        }
        void load(filters, false);
    };

    const onEdit = useCallback((sel: Selection, at: { x: number; y: number }) => setEditing({ sel, at }), []);

    const openUpdate = () => {
        if (!selection) return;
        const cell = document.querySelector<HTMLElement>(`[data-row="${selection.rowId}"][data-i="${selection.end}"]`);
        const r = cell?.getBoundingClientRect();
        setEditing({ sel: selection, at: r ? { x: r.left + r.width / 2, y: r.bottom } : { x: window.innerWidth / 2, y: 200 } });
    };

    const unitOptions = options.units.map((u) => ({ value: u.value, label: u.group ? `${u.label} · ${u.group}` : u.label }));
    const extraCount = EXTRA.filter((k) => !!filters[k]).length;
    const hasFilters = !!(filters.room_type || filters.unit || filters.rate_plan || filters.status) || extraCount > 0;
    const bulkFrom = win.from < win.today ? win.today : win.from;
    const selectionDates = selection && grid ? { from: grid.days[selection.start].date, to: grid.days[selection.end].date } : null;
    const selectedProduct = selection?.kind === 'product' && grid
        ? grid.room_types.find((r) => r.id === selection.roomTypeId)?.products.find((p) => p.id === selection.rowId) : undefined;

    // Copy: from the selection (or the window on screen, at most a month) to the nights right after it.
    const copySource = (() => {
        if (selectionDates) return selectionDates;
        const from = isYear ? win.today.slice(0, 7) + '-01' : win.from;
        const to = isYear ? addDays(addMonths(from, 1), -1) : win.to;
        return { from, to };
    })();
    const copyLength = Math.round((Date.parse(copySource.to) - Date.parse(copySource.from)) / 86400000) + 1;
    const copyTargetFrom = addDays(copySource.to, 1) < win.today ? win.today : addDays(copySource.to, 1);

    return (
        <div className="content cal-page">
            <div className="page-header cal-header">
                <div className="cal-title">
                    <h1 title={t('calendar.title')}>{t('calendar.title')}</h1>
                    <p className="page-desc" title={t('calendar.description')}>{t('calendar.description')}</p>
                </div>
                <div className="page-actions">
                    <div className="seg cal-views" role="tablist" aria-label={t('calendar.title')}>
                        {(['inventory', 'reservations'] as View[]).map((v) => (
                            <button key={v} type="button" role="tab" aria-selected={filters.view === v} className={filters.view === v ? 'active' : ''}
                                onClick={() => filters.view !== v && change({ view: v })}>
                                <Icon name={v === 'inventory' ? 'calendar-days' : 'list'} size={17} />{t(`calendar.views.${v}`)}
                            </button>
                        ))}
                    </div>
                    {can.update && (
                        <Button variant="primary" icon="save" onClick={openUpdate} disabled={!selection} title={selection ? undefined : t('calendar.update_hint')}>{t('calendar.update')}</Button>
                    )}
                </div>
            </div>

            <div className="cal-toolbar">
                <div className="cal-nav">
                    <Button variant="secondary" icon="chevron-left" onClick={() => step(-1)} aria-label={t('calendar.previous')} title={t('calendar.previous')} />
                    <Button variant="secondary" icon="chevron-right" onClick={() => step(1)} aria-label={t('calendar.next')} title={t('calendar.next')} />
                </div>
                <label className="btn btn-secondary cal-month" title={t('calendar.choose_month')}>
                    <Icon name="calendar-days" size={17} />
                    <span>{windowLabel(win.from, win.to, filters.range)}</span>
                    <Icon name="chevron-down" size={16} />
                    <input type="month" aria-label={t('calendar.choose_month')} value={win.from.slice(0, 7)}
                        onClick={(e) => { try { (e.target as HTMLInputElement).showPicker(); } catch { /* older browsers open on focus */ } }}
                        onChange={(e) => e.target.value && change({ from: e.target.value + '-01' })} />
                </label>
                <Select className="cal-range" aria-label={t('calendar.range')} value={filters.range}
                    options={RANGES.map((r) => ({ value: r, label: t(`calendar.ranges.${r}`) }))}
                    onChange={(e) => setRange(e.target.value as Range)} />
                <Button variant="secondary" icon="calendar-check" onClick={goToday}>{t('calendar.today')}</Button>
                <span className="grow" />
                {can.update && <Button variant="secondary" icon="copy" onClick={() => setCopy(true)}>{t('calendar.copy.button')}</Button>}
                {can.update && <Button variant="secondary" icon="layers" onClick={() => setBulk(true)}>{t('calendar.bulk_update')}</Button>}
            </div>

            <div className="filter-bar cal-filters">
                <Select label={t('calendar.filters.room_type')} value={filters.room_type ?? ''} placeholder={t('calendar.filters.all_room_types')}
                    options={options.room_types} onChange={(e) => change({ room_type: e.target.value || null, unit: null })} />
                <Select label={t('calendar.filters.unit')} value={filters.unit ?? ''} placeholder={t('calendar.filters.all_units')}
                    options={unitOptions} onChange={(e) => change({ unit: e.target.value || null })} />
                <Select label={t('calendar.filters.rate_plan')} value={filters.rate_plan ?? ''} placeholder={t('calendar.filters.all_rate_plans')}
                    options={options.rate_plans} onChange={(e) => change({ rate_plan: e.target.value || null })} />
                <Select label={t('calendar.filters.status')} value={filters.status ?? ''} placeholder={t('calendar.filters.active_only')}
                    options={options.statuses} onChange={(e) => change({ status: e.target.value || null })} />
                <Button variant="secondary" icon="filter" className={more ? 'active' : undefined} aria-expanded={more} onClick={() => setMore((m) => !m)}>
                    {t('calendar.filters.more')}{extraCount > 0 && <span className="count-pill num">{extraCount}</span>}
                </Button>
                {hasFilters && (
                    <Button variant="ghost" icon="x" onClick={() => change({ room_type: null, unit: null, rate_plan: null, status: null, availability: null, restriction: null, price_min: null, price_max: null })}>
                        {t('calendar.filters.reset')}
                    </Button>
                )}
            </div>

            {more && (
                <div className="filter-bar cal-filters cal-more" role="group" aria-label={t('calendar.filters.more')}>
                    <Select label={t('calendar.filters.availability')} value={filters.availability ?? ''} placeholder={t('calendar.filters.any_availability')}
                        options={options.availability} onChange={(e) => change({ availability: e.target.value || null })} />
                    <Select label={t('calendar.filters.restriction')} value={filters.restriction ?? ''} placeholder={t('calendar.filters.any_restriction')}
                        options={options.restrictions} onChange={(e) => change({ restriction: e.target.value || null })} />
                    <Input label={t('calendar.filters.price_min')} type="number" min={0} step="0.01" inputMode="decimal" value={price.min}
                        placeholder={t('calendar.filters.price_any')} onChange={(e) => setPrice((p) => ({ ...p, min: e.target.value }))}
                        onBlur={applyPrice} onKeyDown={(e) => e.key === 'Enter' && applyPrice()} />
                    <Input label={t('calendar.filters.price_max')} type="number" min={0} step="0.01" inputMode="decimal" value={price.max}
                        placeholder={t('calendar.filters.price_any')} onChange={(e) => setPrice((p) => ({ ...p, max: e.target.value }))}
                        onBlur={applyPrice} onKeyDown={(e) => e.key === 'Enter' && applyPrice()} />
                    {isYear && <p className="field-hint cal-more-hint">{t('calendar.filters.year_hint')}</p>}
                </div>
            )}

            {isYear ? <YearLegend currency={year?.currency ?? ''} /> : <Legend />}

            <div className={loading ? 'cal-card loading' : 'cal-card'} aria-busy={loading}>
                {isYear ? (
                    year && year.room_types.length > 0
                        ? <YearView year={year} onOpen={(date) => change({ range: 'month', from: date.slice(0, 7) + '-01' })} />
                        : <EmptyState icon="calendar-days" title={t('calendar.empty.title')} text={t('calendar.empty.text')} />
                ) : !grid || grid.room_types.length === 0 ? (
                    <EmptyState icon="calendar-days" title={t('calendar.empty.title')} text={t('calendar.empty.text')}
                        action={!hasFilters ? <LinkButton variant="primary" icon="plus" href={propertyUrl('/room-types/new')}>{t('calendar.empty.add_room_type')}</LinkButton> : undefined} />
                ) : (
                    <CalendarGrid grid={grid} view={filters.view} range={filters.range as 'day' | 'week' | 'month'} canEdit={can.update}
                        selection={selection} onSelect={setSelection} onEdit={onEdit} />
                )}
            </div>

            {editing && grid && <CellEditor key={`${editing.sel.rowId}-${editing.sel.start}-${editing.sel.end}`} grid={grid} selection={editing.sel} at={editing.at}
                onClose={() => setEditing(null)} onSaved={onSaved} />}
            {bulk && (
                <BulkUpdateDrawer open onClose={() => setBulk(false)} onSaved={onSaved}
                    roomTypes={options.room_types} ratePlans={options.rate_plans} minDate={win.today}
                    initial={{
                        from: selectionDates && selectionDates.from >= win.today ? selectionDates.from : bulkFrom,
                        to: selectionDates && selectionDates.to >= win.today ? selectionDates.to : (win.to < bulkFrom ? bulkFrom : win.to),
                        roomTypes: selection ? [selection.roomTypeId] : filters.room_type ? [filters.room_type] : [],
                        ratePlans: selectedProduct ? [selectedProduct.rate_plan.id] : filters.rate_plan ? [filters.rate_plan] : [],
                    }} />
            )}
            {copy && (
                <CopyDialog onClose={() => setCopy(false)} onSaved={onSaved} roomTypes={options.room_types} ratePlans={options.rate_plans} minDate={win.today}
                    initial={{
                        sourceFrom: copySource.from, sourceTo: copySource.to,
                        targetFrom: copyTargetFrom,
                        // A whole month goes to the whole next month; anything else to the same number of nights.
                        targetTo: copySource.from.endsWith('-01') && copyTargetFrom.endsWith('-01') && addDays(copySource.to, 1) === copyTargetFrom
                            ? addDays(addMonths(copyTargetFrom, 1), -1) : addDays(copyTargetFrom, Math.min(copyLength, 366) - 1),
                        roomTypes: selection ? [selection.roomTypeId] : filters.room_type ? [filters.room_type] : [],
                        ratePlans: selectedProduct ? [selectedProduct.rate_plan.id] : filters.rate_plan ? [filters.rate_plan] : options.rate_plans.map((o) => o.value),
                    }} />
            )}
        </div>
    );
}

createPage(CalendarPage);
