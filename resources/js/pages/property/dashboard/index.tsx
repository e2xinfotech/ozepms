import { useState } from 'react';
import { BarLineChart, Donut } from '@/components/charts/Charts';
import { Badge, Card, DateRange, EmptyState, Icon, KpiCard, LinkButton, PageHeader, Progress, Segmented } from '@/components/ui';
import { money, number, percent, shortDate } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t, tOr } from '@/lib/i18n';
import { createPage } from '@/lib/boot';
import { propertyUrl } from '@/lib/page';

interface ChartDay { date: string; occupancy: number; revenue: string; adr: string; revpar: string }
interface StayRow { id: string; ref: string; guest: string; nights: number; guests: number; status: string; source: string | null; source_code?: string | null }
interface Dashboard {
    today: string; from: string; to: string; currency: string;
    kpis: { total_rooms: number; occupied: number; occupancy: number; arrivals: number; departures: number; revenue: string };
    chart: ChartDay[];
    room_status: { occupied: number; vacant: number; out_of_service: number; blocked: number; total: number };
    summary: { arrivals: number; departures: number; housekeeping: number; issues: number };
    arrivals: StayRow[];
    departures: StayRow[];
    housekeeping: { clean: number; dirty: number; inspected: number };
    checklist: { key: string; done: boolean; url: string | null; required: boolean }[];
}

type Metric = 'occupancy' | 'revenue' | 'adr' | 'revpar';



function StayTable({ rows, empty }: { rows: StayRow[]; empty: string }) {
    if (rows.length === 0) return <EmptyState icon="calendar-check" title={empty} />;
    return (
        <div className="table-scroll">
            <table className="table">
                <thead><tr><th>{t('property.dashboard.booking_id')}</th><th>{t('property.dashboard.guest')}</th><th className="num">{t('property.dashboard.nights')}</th><th className="num">{t('property.dashboard.guests')}</th><th>{t('property.dashboard.source')}</th><th>{t('ui.status_label')}</th></tr></thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.id}>
                            <td className="num"><a className="id-link" href={propertyUrl(`/reservations/${r.id}`)}>{r.ref}</a></td>
                            <td className="cell-main">{r.guest}</td>
                            <td className="num">{r.nights}</td>
                            <td className="num">{r.guests}</td>
                            <td>{r.source ? tOr(`reservations.sources.${r.source_code ?? ''}`, r.source) : '—'}</td>
                            <td><Badge size="sm" status={r.status}>{t(`reservations.status.${r.status}`)}</Badge></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Property dashboard: today's figures, occupancy & revenue, room and housekeeping status. */
function DashboardPage({ dashboard: d, max_range_days, onboarding }: { dashboard: Dashboard; max_range_days: number; onboarding: { in_setup: boolean; next_url: string | null } }) {
    const [metric, setMetric] = useState<Metric>('occupancy');
    const cur = d.currency;
    // Steps whose module is not installed yet (no link) do not keep the setup card open.
    const pending = d.checklist.filter((c) => !c.done && c.url);
    const hkTotal = d.housekeeping.clean + d.housekeeping.dirty + d.housekeeping.inspected;
    const labels = d.chart.map((c) => shortDate(c.date));
    const occ = d.chart.map((c) => c.occupancy);
    const series: Record<Metric, number[]> = {
        occupancy: occ,
        revenue: d.chart.map((c) => Number(c.revenue)),
        adr: d.chart.map((c) => Number(c.adr)),
        revpar: d.chart.map((c) => Number(c.revpar)),
    };
    const metricLabel: Record<Metric, string> = {
        occupancy: t('property.dashboard.occupancy_pct_label'),
        revenue: t('property.dashboard.room_revenue', { currency: cur }),
        adr: t('property.dashboard.adr', { currency: cur }),
        revpar: t('property.dashboard.revpar', { currency: cur }),
    };
    const lineMetric: Metric = metric === 'occupancy' ? 'revenue' : metric;

    return (
        <div className="content">
            <PageHeader title={t('nav.dashboard')} description={t('property.dashboard.welcome')}
                actions={<DateRange from={d.from} to={d.to} maxDays={max_range_days} onApply={(from, to) => navigateWithQuery({ from, to })} />} />

            {pending.length > 0 && (
                <Card title={t('property.onboarding.title_short')} actions={
                    <span className="row" style={{ gap: 12 }}>
                        <span className="muted text-sm">{t('property.onboarding.progress', { done: d.checklist.filter((c) => c.done).length, total: d.checklist.length })}</span>
                        {onboarding.next_url && <LinkButton size="sm" variant="primary" icon="arrow-right" href={onboarding.next_url}>{t('property.onboarding.continue')}</LinkButton>}
                    </span>
                }>
                    {onboarding.in_setup && <p className="muted text-sm" style={{ marginBottom: 12 }}>{t('property.onboarding.required_hint')}</p>}
                    <div className="checklist">
                        {d.checklist.map((c, i) => (
                            <div key={c.key} className={`checklist-item${c.done ? ' done' : ''}`}>
                                <span className="ci-no">{c.done ? <Icon name="check" size={16} /> : i + 1}</span>
                                <span className="grow strong">{t(`property.onboarding.${c.key}`)}{c.required && !c.done && <span className="muted text-sm"> · {t('property.onboarding.required')}</span>}</span>
                                {!c.done && c.url && <a className="card-link" href={c.url}>{t('property.onboarding.start')}</a>}
                                {!c.done && !c.url && <span className="muted text-sm">{t('nav.soon')}</span>}
                            </div>
                        ))}
                    </div>
                </Card>
            )}

            <div className="kpi-row">
                <KpiCard icon="bed-double" tone="blue" label={t('property.dashboard.total_rooms')} value={number(d.kpis.total_rooms)} sub={t('property.dashboard.across_room_types')} />
                <KpiCard icon="check-circle" tone="green" label={t('property.dashboard.occupied')} value={number(d.kpis.occupied)} sub={t('property.dashboard.occupancy_pct', { p: number(d.kpis.occupancy, 1) })} />
                <KpiCard icon="user" tone="violet" label={t('property.dashboard.arrivals')} value={number(d.kpis.arrivals)} sub={t('property.dashboard.new_checkins')} />
                <KpiCard icon="briefcase" tone="orange" label={t('property.dashboard.departures')} value={number(d.kpis.departures)} sub={t('property.dashboard.checkouts')} />
                <KpiCard icon="circle-dollar-sign" tone="red" label={t('property.dashboard.revenue')} value={money(d.kpis.revenue, cur)} sub={t('property.dashboard.selected_range')} />
            </div>

            <div className="dash-grid">
                <Card title={t('property.dashboard.occupancy_revenue')} actions={
                    <Segmented active={metric} onChange={(k) => setMetric(k as Metric)} items={[
                        { key: 'occupancy', label: t('property.dashboard.tab_occupancy') },
                        { key: 'revenue', label: t('property.dashboard.tab_revenue') },
                        { key: 'adr', label: t('property.dashboard.tab_adr') },
                        { key: 'revpar', label: t('property.dashboard.tab_revpar') },
                    ]} />
                }>
                    <BarLineChart labels={labels}
                        bars={metric === 'occupancy' ? occ : series[metric]}
                        barMax={metric === 'occupancy' ? 100 : undefined}
                        barLabel={metricLabel[metric]}
                        barFormat={metric === 'occupancy' ? (v) => percent(v) : (v) => number(v)}
                        line={metric === 'occupancy' ? series[lineMetric] : undefined}
                        lineMax={Math.max(100, ...series[lineMetric])}
                        lineLabel={metric === 'occupancy' ? metricLabel[lineMetric] : undefined}
                        lineFormat={(v) => number(v)} />
                    {d.kpis.total_rooms === 0 && <p className="muted text-sm" style={{ textAlign: 'center' }}>{t('property.dashboard.no_data')}</p>}
                </Card>

                <div className="dash-col">
                    <Card title={t('property.dashboard.room_status')}>
                        <Donut centerValue={number(d.room_status.total)} centerLabel={t('property.dashboard.rooms')} segments={[
                            { label: t('ui.status.occupied'), value: d.room_status.occupied, color: 'var(--green-solid)' },
                            { label: t('property.dashboard.vacant'), value: d.room_status.vacant, color: 'var(--line-strong)' },
                            { label: t('ui.status.out_of_service'), value: d.room_status.out_of_service, color: 'var(--amber-solid)' },
                            { label: t('property.dashboard.blocked'), value: d.room_status.blocked, color: 'var(--rose-fg)' },
                        ]} />
                    </Card>
                    <Card title={t('property.dashboard.today_summary')}>
                        <div className="summary-tiles">
                            <div className="summary-tile"><span className="st-value"><Icon name="bed-double" size={20} />{d.summary.arrivals}</span><span className="st-label" title={t('property.dashboard.arrivals_short')}>{t('property.dashboard.arrivals_short')}</span></div>
                            <div className="summary-tile"><span className="st-value"><Icon name="briefcase" size={20} />{d.summary.departures}</span><span className="st-label" title={t('property.dashboard.departures_short')}>{t('property.dashboard.departures_short')}</span></div>
                            <div className="summary-tile"><span className="st-value"><Icon name="spray-can" size={20} />{d.summary.housekeeping}</span><span className="st-label" title={t('property.dashboard.housekeeping_short')}>{t('property.dashboard.housekeeping_short')}</span></div>
                            <div className={`summary-tile${d.summary.issues > 0 ? ' alert-tile' : ''}`}><span className="st-value"><Icon name="alert-triangle" size={20} />{d.summary.issues}</span><span className="st-label">{t('property.dashboard.issues')}</span></div>
                        </div>
                    </Card>
                </div>
            </div>

            <div className="dash-grid-3">
                <Card flush title={`${t('property.dashboard.arrivals_today')} (${d.summary.arrivals})`} actions={<a className="card-link" href={propertyUrl('/front-desk')}>{t('ui.view_all')}</a>}>
                    <StayTable rows={d.arrivals} empty={t('property.dashboard.no_arrivals')} />
                </Card>
                <Card flush title={`${t('property.dashboard.departures_today')} (${d.summary.departures})`} actions={<a className="card-link" href={propertyUrl('/front-desk?tab=departures')}>{t('ui.view_all')}</a>}>
                    <StayTable rows={d.departures} empty={t('property.dashboard.no_departures')} />
                </Card>
                <Card title={t('property.dashboard.housekeeping')}>
                    <div className="bar-list">
                        {([['clean', 'check-circle', 'green'], ['dirty', 'spray-can', 'amber'], ['inspected', 'clipboard-check', 'blue']] as const).map(([key, icon, tone]) => {
                            const value = d.housekeeping[key];
                            const pct = hkTotal > 0 ? (value * 100) / hkTotal : 0;
                            return (
                                <div key={key} className="bar-list-row">
                                    <span className="row" style={{ gap: 10 }}><span className={`tone-${tone}`} style={{ borderRadius: '50%', width: 28, height: 28, display: 'grid', placeItems: 'center' }}><Icon name={icon} size={16} /></span>{t(`ui.status.${key}`)}</span>
                                    <span className="num strong right">{value}</span>
                                    <Progress value={pct} tone={tone === 'green' ? undefined : tone === 'amber' ? 'amber' : 'blue'} />
                                    <span className="num muted right">{percent(pct, 1)}</span>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </div>
        </div>
    );
}

createPage(DashboardPage);
