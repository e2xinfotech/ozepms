import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, EmptyState, Icon, LinkButton, Select, toast } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { ApiError, http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { BulkUpdateDrawer } from './_components/BulkUpdateDrawer';
import { CalendarGrid } from './_components/CalendarGrid';
import { CellEditor, type SaveResult } from './_components/CellEditor';
import { addDays, addMonths, windowLabel } from './_components/dates';
import { Legend } from './_components/Legend';
import type { Filters, Grid, Option, Range, Selection, View } from './_components/types';

interface Props {
    grid: Grid;
    filters: Filters;
    options: {
        room_types: (Option & { code: string })[];
        rate_plans: Option[];
        units: (Option & { group: string | null })[];
        statuses: Option[];
    };
    can: { update: boolean; reservations: boolean };
}

const QUERY_KEYS: (keyof Filters)[] = ['view', 'range', 'from', 'room_type', 'unit', 'rate_plan', 'status'];

function filtersFromUrl(fallback: Filters): Filters {
    const q = new URL(window.location.href).searchParams;
    const get = (k: string) => q.get(k) || null;
    return {
        view: get('view') === 'reservations' ? 'reservations' : 'inventory',
        range: get('range') === 'week' ? 'week' : 'month',
        from: get('from') ?? fallback.from,
        room_type: get('room_type'), unit: get('unit'), rate_plan: get('rate_plan'), status: get('status'),
    };
}

/**
 * Inventory, rates & restrictions calendar (designs: calendar-ari, calendar-view-toggle, calendar-reservation-bars).
 * The first window comes with the page; every navigation or filter change loads exactly one window
 * from /calendar/grid and keeps the query string in step, so Back, Refresh and bookmarks work.
 */
function CalendarPage({ grid: initialGrid, filters: initialFilters, options, can }: Props) {
    const [grid, setGrid] = useState(initialGrid);
    const [filters, setFilters] = useState<Filters>(initialFilters);
    const [loading, setLoading] = useState(false);
    const [selection, setSelection] = useState<Selection | null>(null);
    const [editing, setEditing] = useState<{ sel: Selection; at: { x: number; y: number } } | null>(null);
    const [bulk, setBulk] = useState(false);
    const seq = useRef(0);
    const monthInput = useRef<HTMLInputElement>(null);

    const load = useCallback(async (next: Filters, push = true) => {
        setFilters(next);
        setSelection(null);
        setEditing(null);
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
        try {
            const res = await http.get<{ grid: Grid }>(propertyApiUrl('/calendar/grid'), {
                range: next.range, from: next.from, room_type: next.room_type, unit: next.unit, rate_plan: next.rate_plan, status: next.status,
            });
            if (id === seq.current) setGrid(res.grid);
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

    const change = (patch: Partial<Filters>) => void load({ ...filters, ...patch });
    const step = (dir: -1 | 1) => change({ from: filters.range === 'month' ? addMonths(grid.from, dir) : addDays(grid.from, dir * 14) });
    const today = () => change({ from: filters.range === 'month' ? grid.today.slice(0, 7) + '-01' : grid.today });

    const onSaved = (res: SaveResult) => {
        setEditing(null);
        setBulk(false);
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
    const hasFilters = !!(filters.room_type || filters.unit || filters.rate_plan || filters.status);
    const bulkFrom = grid.from < grid.today ? grid.today : grid.from;
    const selectionDates = selection ? { from: grid.days[selection.start].date, to: grid.days[selection.end].date } : null;
    const selectedProduct = selection?.kind === 'product'
        ? grid.room_types.find((r) => r.id === selection.roomTypeId)?.products.find((p) => p.id === selection.rowId) : undefined;

    return (
        <div className="content cal-page">
            <div className="page-header cal-header">
                <div>
                    <h1>{t('calendar.title')}</h1>
                    <p className="page-desc">{t('calendar.description')}</p>
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
                    <div className="cal-nav">
                        <Button variant="secondary" icon="chevron-left" onClick={() => step(-1)} aria-label={t('calendar.previous')} title={t('calendar.previous')} />
                        <Button variant="secondary" icon="chevron-right" onClick={() => step(1)} aria-label={t('calendar.next')} title={t('calendar.next')} />
                    </div>
                    <label className="btn btn-secondary cal-month" title={t('calendar.choose_month')}>
                        <Icon name="calendar-days" size={17} />
                        <span>{windowLabel(grid.from, grid.to, filters.range)}</span>
                        <Icon name="chevron-down" size={16} />
                        <input ref={monthInput} type="month" aria-label={t('calendar.choose_month')} value={grid.from.slice(0, 7)}
                            onClick={(e) => { try { (e.target as HTMLInputElement).showPicker(); } catch { /* older browsers open on focus */ } }}
                            onChange={(e) => e.target.value && change({ from: filters.range === 'month' ? e.target.value + '-01' : e.target.value + '-01' })} />
                    </label>
                    <Select className="cal-range" aria-label={t('calendar.ranges.month')} value={filters.range}
                        options={(['month', 'week'] as Range[]).map((r) => ({ value: r, label: t(`calendar.ranges.${r}`) }))}
                        onChange={(e) => change({ range: e.target.value as Range, from: e.target.value === 'month' ? grid.from.slice(0, 7) + '-01' : (grid.from <= grid.today && grid.today <= grid.to ? grid.today : grid.from) })} />
                    {can.update && (
                        <Button variant="primary" icon="save" onClick={openUpdate} disabled={!selection} title={selection ? undefined : t('calendar.update_hint')}>{t('calendar.update')}</Button>
                    )}
                </div>
            </div>

            <div className="filter-bar cal-filters">
                <Select label={t('calendar.filters.room_type')} value={filters.room_type ?? ''} placeholder={t('calendar.filters.all_room_types')}
                    options={options.room_types} onChange={(e) => change({ room_type: e.target.value || null, unit: null })} />
                <Select label={t('calendar.filters.unit')} value={filters.unit ?? ''} placeholder={t('calendar.filters.all_units')}
                    options={unitOptions} onChange={(e) => change({ unit: e.target.value || null })} />
                <Select label={t('calendar.filters.rate_plan')} value={filters.rate_plan ?? ''} placeholder={t('calendar.filters.all_rate_plans')}
                    options={options.rate_plans} onChange={(e) => change({ rate_plan: e.target.value || null })} />
                <Select label={t('calendar.filters.status')} value={filters.status ?? ''} placeholder={t('calendar.filters.all_statuses')}
                    options={options.statuses.filter((s) => s.value !== 'active')} onChange={(e) => change({ status: e.target.value || null })} />
                {hasFilters && <Button variant="ghost" icon="x" onClick={() => change({ room_type: null, unit: null, rate_plan: null, status: null })}>{t('calendar.filters.reset')}</Button>}
                <span className="grow" />
                <Button variant="secondary" icon="calendar-check" onClick={today}>{t('calendar.today')}</Button>
                {can.update && <Button variant="secondary" icon="layers" onClick={() => setBulk(true)}>{t('calendar.bulk_update')}</Button>}
            </div>

            <Legend />

            <div className={loading ? 'cal-card loading' : 'cal-card'} aria-busy={loading}>
                {grid.room_types.length === 0 ? (
                    <EmptyState icon="calendar-days" title={t('calendar.empty.title')} text={t('calendar.empty.text')}
                        action={!hasFilters ? <LinkButton variant="primary" icon="plus" href={propertyUrl('/room-types/new')}>{t('calendar.empty.add_room_type')}</LinkButton> : undefined} />
                ) : (
                    <CalendarGrid grid={grid} view={filters.view} range={filters.range} canEdit={can.update}
                        selection={selection} onSelect={setSelection} onEdit={onEdit} />
                )}
            </div>

            {editing && <CellEditor key={`${editing.sel.rowId}-${editing.sel.start}-${editing.sel.end}`} grid={grid} selection={editing.sel} at={editing.at}
                onClose={() => setEditing(null)} onSaved={onSaved} />}
            {bulk && (
                <BulkUpdateDrawer open onClose={() => setBulk(false)} onSaved={onSaved}
                    roomTypes={options.room_types} ratePlans={options.rate_plans} minDate={grid.today}
                    initial={{
                        from: selectionDates && selectionDates.from >= grid.today ? selectionDates.from : bulkFrom,
                        to: selectionDates && selectionDates.to >= grid.today ? selectionDates.to : (grid.to < bulkFrom ? bulkFrom : grid.to),
                        roomTypes: selection ? [selection.roomTypeId] : filters.room_type ? [filters.room_type] : [],
                        ratePlans: selectedProduct ? [selectedProduct.rate_plan.id] : filters.rate_plan ? [filters.rate_plan] : [],
                    }} />
            )}
        </div>
    );
}

createPage(CalendarPage);
