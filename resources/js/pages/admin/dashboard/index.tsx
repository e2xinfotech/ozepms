import { BarLineChart, Donut } from '@/components/charts/Charts';
import { Badge, Card, EmptyState, Flag, Icon, KpiCard, LinkButton, PageHeader, Select } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date, number, relative } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Props {
    summary: { properties: number; properties_active: number; properties_inactive: number; properties_onboarding: number; countries: number; rooms: number; users: number; bookings_month: number; revenue_month: string | null };
    distribution: { type: string; label: string; total: number }[];
    activity: { action: string; label: string; user: string | null; property: string | null; at: string | null }[];
    system: { open_errors: number; errors_24h: number; expiring_subscriptions: number; onboarding_properties: number; connected_channels: number };
    bookings: { date: string; bookings: number }[];
    days: number;
    periods: number[];
    properties: { code: string; name: string; location: string; country_code: string | null; type_label: string | null; rooms: number; status: string }[];
}

const palette = ['var(--blue-solid)', 'var(--green-solid)', 'var(--amber-solid)', 'var(--violet-solid)', 'var(--orange-solid)', 'var(--red-solid)', 'var(--slate-fg)'];

const activityIcon = (action: string): [string, string] => {
    if (action.startsWith('auth.')) return ['user', 'tone-blue'];
    if (action.startsWith('property.')) return ['building-2', 'tone-violet'];
    if (action.startsWith('subscription.') || action.startsWith('plan.')) return ['credit-card', 'tone-amber'];
    if (action.startsWith('role.') || action.includes('role')) return ['user-cog', 'tone-teal'];
    return ['history', 'tone-green'];
};

/** Super Admin dashboard: platform-wide figures, activity and system overview. */
function AdminDashboardPage(p: Props) {
    const s = p.summary;
    const totalByType = p.distribution.reduce((a, d) => a + d.total, 0);

    return (
        <div className="content">
            <PageHeader title={t('admin.dashboard_title')} description={t('admin.dashboard_sub')} />

            <div className="kpi-row">
                <KpiCard icon="building-2" tone="blue" label={t('admin.kpi.properties')} value={number(s.properties)} sub={t('property.kpi.across_countries', { n: s.countries })} />
                <KpiCard icon="bed-double" tone="sky" label={t('admin.kpi.rooms')} value={number(s.rooms)} sub={t('admin.kpi.all_properties')} />
                <KpiCard icon="users" tone="violet" label={t('admin.kpi.users')} value={number(s.users)} sub={t('admin.kpi.active_users')} />
                <KpiCard icon="calendar-check" tone="green" label={t('admin.kpi.bookings')} value={number(s.bookings_month)} sub={t('admin.kpi.this_month')} />
                <KpiCard icon="circle-dollar-sign" tone="red" label={t('admin.kpi.revenue')} value={s.revenue_month ?? '—'} sub={t('admin.kpi.this_month_all')} />
            </div>

            <div className="dash-grid">
                <div className="dash-col">
                    <div className="dash-grid" style={{ gridTemplateColumns: 'minmax(0, 1.6fr) minmax(0, 1fr)' }}>
                        <Card title={t('admin.booking_performance')} actions={
                            <Select size="sm" aria-label={t('ui.date_range')} value={String(p.days)} options={p.periods.map((d) => ({ value: String(d), label: t('admin.last_days', { n: d }) }))}
                                onChange={(e) => navigateWithQuery({ days: e.target.value })} />
                        }>
                            <BarLineChart labels={p.bookings.map((b) => date(b.date).replace(/\s\d{4}$/, ''))} bars={p.bookings.map((b) => b.bookings)} barLabel={t('admin.bookings')} />
                            {s.bookings_month === 0 && <p className="muted text-sm" style={{ textAlign: 'center' }}>{t('admin.no_bookings_yet')}</p>}
                        </Card>
                        <Card title={t('admin.property_distribution')}>
                            {totalByType === 0 ? <EmptyState icon="building-2" title={t('property.no_properties')} /> : (
                                <Donut centerValue={number(totalByType)} centerLabel={t('nav.properties')} size={150}
                                    segments={p.distribution.map((d, i) => ({ label: d.label, value: d.total, color: palette[i % palette.length] }))} />
                            )}
                        </Card>
                    </div>

                    <Card flush title={t('admin.properties_overview')} actions={<a className="card-link" href="/admin/properties">{t('ui.view_all')}</a>}>
                        {p.properties.length === 0 ? <EmptyState icon="building-2" title={t('property.no_properties')} text={t('property.no_properties_hint')} /> : (
                            <div className="table-scroll">
                                <table className="table">
                                    <thead><tr><th>{t('property.property')}</th><th>{t('property.code_short')}</th><th>{t('property.location')}</th><th>{t('property.type_short')}</th><th className="num">{t('property.total_rooms')}</th><th>{t('ui.status_label')}</th><th className="col-actions">{t('ui.actions')}</th></tr></thead>
                                    <tbody>
                                        {p.properties.map((r) => (
                                            <tr key={r.code}>
                                                <td className="cell-main">{r.name}</td>
                                                <td className="num">{r.code}</td>
                                                <td><span className="row" style={{ gap: 8 }}><Flag code={r.country_code} />{r.location || '—'}</span></td>
                                                <td>{r.type_label ?? '—'}</td>
                                                <td className="num">{number(r.rooms)}</td>
                                                <td><Badge status={r.status} /></td>
                                                <td className="col-actions"><a className="btn btn-outline btn-sm" href={`/admin/properties?selected=${r.code}`}>{t('ui.view')}</a></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="dash-col">
                    <Card title={t('admin.recent_activities')} actions={<a className="card-link" href="/admin/audit">{t('ui.view_all')}</a>}>
                        {p.activity.length === 0 ? <EmptyState icon="history" title={t('users.no_activity')} /> : (
                            <ul className="list-plain">
                                {p.activity.map((a, i) => {
                                    const [icon, tone] = activityIcon(a.action);
                                    return (
                                        <li key={i} className="activity">
                                            <span className={`a-icon ${tone}`}><Icon name={icon} size={17} /></span>
                                            <div className="grow"><div className="a-title">{a.label}</div><div className="a-sub">{[a.user, a.property].filter(Boolean).join(' · ')}</div></div>
                                            <span className="a-time" title={a.at ?? ''}>{relative(a.at)}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Card>

                    <Card title={t('admin.system_overview')} actions={<a className="card-link" href="/admin/system">{t('admin.view_details')}</a>}>
                        <div className="mini-stats">
                            <div className="mini-stat"><span className="kpi-icon tone-blue" style={{ width: 40, height: 40 }}><Icon name="network" size={18} /></span><div><span className="text-sm strong">{t('admin.connected_channels')}</span><b className="num">{number(p.system.connected_channels)}</b></div></div>
                            <div className="mini-stat"><span className="kpi-icon tone-red" style={{ width: 40, height: 40 }}><Icon name="alert-triangle" size={18} /></span><div><span className="text-sm strong">{t('admin.upcoming_expirations')}</span><b className="num">{number(p.system.expiring_subscriptions)}</b><span className="text-xs muted">{t('admin.subscriptions_expiring')}</span></div></div>
                            <div className="mini-stat"><span className="kpi-icon tone-amber" style={{ width: 40, height: 40 }}><Icon name="clock" size={18} /></span><div><span className="text-sm strong">{t('admin.pending_setup')}</span><b className="num">{number(p.system.onboarding_properties)}</b><span className="text-xs muted">{t('admin.properties_in_setup')}</span></div></div>
                            <div className="mini-stat"><span className={`kpi-icon ${p.system.open_errors > 0 ? 'tone-red' : 'tone-green'}`} style={{ width: 40, height: 40 }}><Icon name={p.system.open_errors > 0 ? 'circle-alert' : 'check-circle'} size={18} /></span><div><span className="text-sm strong">{t('admin.system_health')}</span><b className="num">{number(p.system.open_errors)}</b><span className="text-xs muted">{t('admin.open_errors')}</span></div></div>
                        </div>
                    </Card>

                    <Card title={t('ui.quick_actions')}>
                        <div className="action-grid">
                            <LinkButton variant="outline" icon="building-2" href="/admin/properties/new">{t('admin.add_property')}</LinkButton>
                            <LinkButton variant="outline" icon="user-plus" href="/admin/users?new=1">{t('admin.add_user')}</LinkButton>
                            <LinkButton variant="outline" icon="scroll-text" href="/admin/audit">{t('nav.audit')}</LinkButton>
                            <LinkButton variant="outline" icon="credit-card" href="/admin/plans">{t('nav.subscriptions')}</LinkButton>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    );
}

createPage(AdminDashboardPage);
