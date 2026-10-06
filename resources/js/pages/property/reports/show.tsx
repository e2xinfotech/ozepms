import { useRef, useState } from 'react';
import { BarLineChart } from '@/components/charts/Charts';
import { Alert, Button, Card, EmptyState, Icon, Input, KpiCard, PageHeader, Segmented, Select } from '@/components/ui';
import { cell, isNumeric, periodLabel, plain } from '@/components/reports/format';
import type { CatalogItem, ReportData, ReportFilters, ReportKpi, ReportOptions, ReportTable } from '@/components/reports/types';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t, tc } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';

interface ReportMeta { key: string; slug: string; title: string; description: string; icon: string; group: string; filters: string[]; range: string }
interface Props { report: ReportMeta; catalog: CatalogItem[]; filters: ReportFilters; data: ReportData; options: ReportOptions }

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s: string) => new Date(`${s}T00:00:00`);
const addDays = (s: string, n: number) => { const d = parse(s); d.setDate(d.getDate() + n); return iso(d); };

function presets(today: string, range: string): Record<string, [string, string]> {
    const d = parse(today);
    const first = iso(new Date(d.getFullYear(), d.getMonth(), 1));
    const last = iso(new Date(d.getFullYear(), d.getMonth() + 1, 0));
    const all: Record<string, [string, string]> = {
        this_month: [first, last],
        last_month: [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))],
        last_30: [addDays(today, -29), today],
        next_30: [today, addDays(today, 29)],
        next_90: [today, addDays(today, 89)],
        this_year: [iso(new Date(d.getFullYear(), 0, 1)), iso(new Date(d.getFullYear(), 11, 31))],
    };
    const keys = range === 'future' ? ['next_30', 'next_90', 'this_month'] : ['this_month', 'last_month', 'last_30', 'next_30', 'this_year'];
    return Object.fromEntries(keys.map((k) => [k, all[k]]));
}

/** One report: filters, KPI tiles (with change against the previous period), chart and tables; CSV export. */
function ReportPage({ report, catalog, filters: initialFilters, data: initialData, options }: Props) {
    const [filters, setFilters] = useState(initialFilters);
    const [data, setData] = useState(initialData);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const seq = useRef(0);
    const has = (f: string) => report.filters.includes(f);
    const cur = options.currency;
    const ranges = presets(options.today, report.range);
    const preset = Object.entries(ranges).find(([, [a, b]]) => a === filters.from && b === filters.to)?.[0] ?? 'custom';

    const query = (f: ReportFilters): Record<string, string | number | null> => {
        const q: Record<string, string | number | null> = {};
        if (has('date')) q.date = f.date;
        if (has('range')) { q.from = f.from; q.to = f.to; }
        if (has('group')) q.group = f.group;
        if (has('room_type')) q.room_type = f.room_type;
        if (has('source')) q.source = f.source;
        if (has('status')) q.status = f.status;
        if (has('basis')) q.basis = f.basis;
        if (has('pickup_days')) q.pickup_days = f.pickup_days;
        if (f.page > 1) q.page = f.page;
        return q;
    };
    const qs = (q: Record<string, string | number | null>) => new URLSearchParams(Object.entries(q).filter(([, v]) => v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString();

    const load = async (changes: Partial<ReportFilters>, keepPage = false) => {
        const next = { ...filters, ...changes, page: keepPage ? (changes.page ?? filters.page) : 1 };
        setFilters(next);
        const q = query(next);
        window.history.replaceState(null, '', propertyUrl(`/reports/${report.slug}`) + (qs(q) ? `?${qs(q)}` : ''));
        const mine = ++seq.current;
        setBusy(true);
        setError(null);
        try {
            const res = await http.get<{ filters: ReportFilters; data: ReportData }>(propertyApiUrl(`/reports/${report.slug}`), q);
            if (mine !== seq.current) return;
            setData(res.data);
            setFilters((f) => ({ ...f, ...res.filters, room_type: next.room_type, source: next.source, status: next.status }));
        } catch (e) {
            if (mine === seq.current) setError(e as ApiError);
        } finally {
            if (mine === seq.current) setBusy(false);
        }
    };

    const setRange = (from: string, to: string) => load({ from, to });
    const exportUrl = propertyApiUrl(`/reports/${report.slug}/export`) + `?${qs({ ...query(filters), page: null })}`;
    const fieldError = error ? (error.field('to') ?? error.field('from') ?? error.field('date') ?? (Object.keys(error.fields ?? {}).length ? null : error.message)) : null;

    return (
        <div className="content report-page">
            <PageHeader title={report.title} description={report.description} back={propertyUrl('/reports')}
                actions={<div className="report-actions row" style={{ gap: 8 }}>
                    <Button variant="outline" icon="printer" onClick={() => window.print()}>{t('reports.print')}</Button>
                    <a className="btn btn-primary" href={exportUrl}><Icon name="download" size={18} />{t('reports.export')}</a>
                </div>} />

            <nav className="report-nav" aria-label={t('reports.all_reports')}>
                {catalog.map((c) => <a key={c.key} href={c.url} className={c.key === report.key ? 'active' : undefined}>{c.title}</a>)}
            </nav>

            <div className="report-filters card">
                {has('date') && <>
                    <Input type="date" label={t('reports.filters.date')} value={filters.date} onChange={(e) => e.target.value && load({ date: e.target.value })} />
                    <div className="field report-day-nav">
                        <span className="field-label">&nbsp;</span>
                        <div className="row" style={{ gap: 6 }}>
                            <Button variant="outline" icon="chevron-left" aria-label={t('reports.filters.previous_day')} onClick={() => load({ date: addDays(filters.date, -1) })} />
                            <Button variant="outline" onClick={() => load({ date: options.today })}>{t('reports.filters.today')}</Button>
                            <Button variant="outline" icon="chevron-right" aria-label={t('reports.filters.next_day')} onClick={() => load({ date: addDays(filters.date, 1) })} />
                        </div>
                    </div>
                </>}
                {has('range') && <>
                    <Select label={t('reports.filters.period')} value={preset}
                        options={[...Object.keys(ranges).map((k) => ({ value: k, label: t(`reports.filters.presets.${k}`) })), { value: 'custom', label: t('reports.filters.presets.custom') }]}
                        onChange={(e) => { const r = ranges[e.target.value]; if (r) setRange(r[0], r[1]); }} />
                    <Input type="date" label={t('reports.filters.from')} value={filters.from} onChange={(e) => e.target.value && setRange(e.target.value, filters.to < e.target.value ? e.target.value : filters.to)} />
                    <Input type="date" label={t('reports.filters.to')} value={filters.to} min={filters.from} onChange={(e) => e.target.value && setRange(filters.from, e.target.value)} />
                </>}
                {has('group') && <div className="field">
                    <span className="field-label">{t('reports.filters.group')}</span>
                    <Segmented items={['day', 'week', 'month'].map((g) => ({ key: g, label: t(`reports.filters.${g}`) }))} active={filters.group} onChange={(g) => load({ group: g })} />
                </div>}
                {has('room_type') && <Select label={t('reports.filters.room_type')} value={filters.room_type ?? ''} placeholder={t('reports.filters.all_room_types')}
                    options={options.room_types} onChange={(e) => load({ room_type: e.target.value || null })} />}
                {has('basis') && <Select label={t('reports.filters.basis')} value={filters.basis}
                    options={['booked', 'arrival', 'stay'].map((b) => ({ value: b, label: t(`reports.filters.basis_${b}`) }))} onChange={(e) => load({ basis: e.target.value })} />}
                {has('status') && <Select label={t('reports.filters.status')} value={filters.status ?? ''} placeholder={t('reports.filters.all_statuses')}
                    options={options.statuses} onChange={(e) => load({ status: e.target.value || null })} />}
                {has('source') && <Select label={t('reports.filters.source')} value={filters.source ?? ''} placeholder={t('reports.filters.all_sources')}
                    options={options.sources} onChange={(e) => load({ source: e.target.value || null })} />}
                {has('pickup_days') && <Select label={t('reports.filters.pickup_days')} value={String(filters.pickup_days)}
                    options={[1, 3, 7, 14, 30].map((d) => ({ value: String(d), label: t('reports.filters.pickup_option', { days: d }) }))} onChange={(e) => load({ pickup_days: Number(e.target.value) })} />}
                {busy && <span className="report-busy muted text-sm"><Icon name="loader" size={16} className="spin" /> {t('reports.loading')}</span>}
            </div>

            {fieldError && <Alert tone="danger">{fieldError}</Alert>}

            <div className={busy ? 'report-body is-busy' : 'report-body'}>
                {data.summary.length > 0 && <div className="kpi-row report-kpis">
                    {data.summary.map((k) => <Kpi key={k.key} k={k} cur={cur} />)}
                </div>}

                {data.chart && <Card className="report-chart">
                    <BarLineChart labels={data.chart.labels.map((l) => (data.chart!.label_type === 'period' ? periodLabel(l, filters.group, true) : l.length > 14 ? `${l.slice(0, 13)}…` : l))}
                        bars={data.chart.bars} line={data.chart.line} barLabel={data.chart.bar_label} lineLabel={data.chart.line_label}
                        barFormat={(v) => plain(data.chart!.bar_type, v, cur, 'axis')} lineFormat={(v) => plain(data.chart!.line_type, v, cur, 'axis')}
                        barMax={data.chart.bar_type === 'percent' ? 100 : undefined} lineMax={data.chart.line_type === 'percent' ? 100 : undefined} />
                </Card>}

                {data.tables.map((tb) => <TableCard key={tb.key} table={tb} cur={cur} group={filters.group} onPage={(page) => load({ page }, true)} />)}
            </div>
        </div>
    );
}

function Kpi({ k, cur }: { k: ReportKpi; cur: string }) {
    const value = plain(k.type, k.value, cur, 'tile');
    const full = plain(k.type, k.value, cur);
    const compare = k.previous !== null && k.previous !== undefined && k.type !== 'text';
    let sub: string | undefined;
    if (compare) {
        const now = Number(k.value), before = Number(k.previous);
        const change = before > 0 ? Math.round(((now - before) / before) * 1000) / 10 : null;
        const arrow = change === null || change === 0 ? '' : change > 0 ? '▲ ' : '▼ ';
        sub = `${change === null ? '' : `${arrow}${Math.abs(change)}% · `}${t('reports.vs_previous')}: ${plain(k.type, k.previous, cur, 'tile')}`;
    }
    return <KpiCard compact fit icon={iconOf(k.key)} tone={toneOf(k.key)} label={k.label} value={value} title={full} sub={sub} />;
}

function TableCard({ table, cur, group, onPage }: { table: ReportTable; cur: string; group: string; onPage: (page: number) => void }) {
    const p = table.pagination;
    return (
        <Card title={table.title} flush className="report-table"
            actions={p ? <span className="muted text-sm">{tc('reports.rows', p.total)}</span> : undefined}>
            {table.rows.length === 0 ? <EmptyState icon="chart-column" title={t('reports.no_data')} /> : (
                <div className="table-scroll">
                    <table className="table">
                        <thead><tr>{table.columns.map((c) => <th key={c.key} className={isNumeric(c.type) ? 'num' : undefined}>{c.label}</th>)}</tr></thead>
                        <tbody>
                            {table.rows.map((r, i) => <tr key={String(r.id ?? r.period ?? r.name ?? r.metric ?? i) + i}>
                                {table.columns.map((c) => <td key={c.key} className={isNumeric(c.type) ? 'num' : c.type === 'date' || c.type === 'status' || c.type === 'ref' ? 'nowrap' : c.type === 'text' && c.key === table.columns[0].key ? 'cell-main' : undefined}>{cell(c.type, r[c.key], r, cur, group)}</td>)}
                            </tr>)}
                        </tbody>
                        {table.totals && <tfoot><tr>{table.columns.map((c) => <td key={c.key} className={isNumeric(c.type) ? 'num' : undefined}>
                            {table.totals![c.key] === undefined ? '' : c.type === 'period' ? String(table.totals![c.key]) : cell(c.type, table.totals![c.key], table.totals!, cur, group)}
                        </td>)}</tr></tfoot>}
                    </table>
                </div>
            )}
            {p && p.last_page > 1 && <div className="report-pager">
                <Button variant="outline" size="sm" icon="chevron-left" disabled={p.page <= 1} onClick={() => onPage(p.page - 1)}>{t('reports.previous_page')}</Button>
                <span className="muted text-sm">{t('reports.page', { page: p.page, pages: p.last_page })}</span>
                <Button variant="outline" size="sm" disabled={p.page >= p.last_page} onClick={() => onPage(p.page + 1)}>{t('reports.next_page')} <Icon name="chevron-right" size={15} /></Button>
            </div>}
        </Card>
    );
}

function iconOf(key: string): string {
    if (key.includes('occupancy')) return 'bed-double';
    if (key.includes('cancel')) return 'calendar-x';
    if (key.includes('no_show')) return 'circle-slash';
    if (/revenue|adr|revpar|value|fees|taxes|tax|payments|extras|charges/.test(key)) return 'wallet';
    if (/arrivals/.test(key)) return 'log-in';
    if (/departures/.test(key)) return 'log-out';
    if (/pickup|books/.test(key)) return 'trending-up';
    if (/top_/.test(key)) return 'star';
    if (/lead|los|notice/.test(key)) return 'clock';
    if (/invoices|credit/.test(key)) return 'file-text';
    return 'calendar-check';
}

function toneOf(key: string): 'blue' | 'green' | 'amber' | 'red' | 'purple' {
    if (/cancel|no_show|lost|ooo/.test(key)) return 'red';
    if (/revenue|adr|revpar|value|payments|charges|extras|fees/.test(key)) return 'green';
    if (/tax|invoice|credit|lead|los|notice/.test(key)) return 'amber';
    if (/top_|pickup|books/.test(key)) return 'purple';
    return 'blue';
}

createPage(ReportPage);
