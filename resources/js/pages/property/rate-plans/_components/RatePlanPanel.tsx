import { useEffect, useState } from 'react';
import { Badge, Button, EmptyState, Icon, KeyValue, LinkButton, SidePanel, Tabs } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { payload, propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, currency, price } from '../../_accommodation/shared';
import { ChannelIcons, nightsLabel, PolicyBadge, PolicyRules } from './RatePlanBits';
import type { RatePlanDetail } from './types';

/** "Rate Plan Details" panel (design: rate-plans-v2.png). */
export function RatePlanPanel({ id, canUpdate, canCreate, onClose }: { id: string; canUpdate: boolean; canCreate: boolean; onClose: () => void }) {
    const [plan, setPlan] = useState<RatePlanDetail | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [tab, setTab] = useState('overview');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        setFailed(null);
        http.get<{ rate_plan: RatePlanDetail }>(propertyApiUrl(`/rate-plans/${id}`))
            .then((res) => alive && setPlan(res.rate_plan))
            .catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id]);

    if (failed) return <SidePanel title={t('rates.details_title')} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!plan || plan.id !== id) return <SidePanel title={t('rates.details_title')} onClose={onClose}><div className="sp-section stack"><div className="skeleton" style={{ height: 60 }} /><div className="skeleton" style={{ height: 260 }} /></div></SidePanel>;

    const toggle = async () => {
        setBusy(true);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`/rate-plans/${plan.id}/status`), { is_active: !plan.is_active }));
        setBusy(false);
        if (res) window.location.reload();
    };
    const copy = async () => {
        setBusy(true);
        const res = await act(() => http.post<{ message: string; rate_plan: { id: string } }>(propertyApiUrl(`/rate-plans/${plan.id}/copy`)));
        setBusy(false);
        if (res) window.location.href = propertyUrl(`/rate-plans?selected=${res.rate_plan.id}`);
    };

    const calendarUrl = payload().shell.menu?.find((m) => m.key === 'calendar')?.url ?? null;
    const window_ = plan.min_advance_days === null && plan.max_advance_days === null
        ? t('rates.any')
        : t('rates.days_range', { from: plan.min_advance_days ?? 0, to: plan.max_advance_days ?? '∞' });
    const roomTypes = plan.all_room_types ? t('rates.all_room_types') : plan.room_types.join(', ') || t('rates.none_linked');

    return (
        <SidePanel title={t('rates.details_title')} onClose={onClose}>
            <div className="sp-section row-between" style={{ borderTop: 0, paddingTop: 0 }}>
                <div className="row" style={{ flexWrap: 'wrap' }}>
                    <h3 style={{ fontSize: 20 }}>{plan.name}</h3>
                    <Badge status={plan.is_active ? 'active' : 'inactive'} />
                    {plan.is_default && <Badge tone="blue" size="sm">{t('rates.default_badge')}</Badge>}
                </div>
                {canUpdate && <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/rate-plans/${plan.id}/edit`)}>{t('ui.edit')}</LinkButton>}
            </div>
            <div className="panel-tabs" style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={[
                    { key: 'overview', label: t('rates.panel_tabs.overview') },
                    { key: 'rates', label: t('rates.panel_tabs.rates') },
                    { key: 'room_types', label: t('rates.panel_tabs.room_types') },
                    { key: 'channels', label: t('rates.panel_tabs.channels') },
                ]} />
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'overview' && <KeyValue items={[
                    { label: t('rates.fields.code'), value: plan.code },
                    { label: t('rates.fields.meal_plan'), value: plan.meal_plan?.label },
                    { label: t('rates.fields.description'), value: plan.description },
                    { label: t('rates.fields.policy'), value: plan.cancellation_policy ? <span>{plan.cancellation_policy.name}</span> : null },
                    { label: t('rates.fields.base_rate'), value: plan.base_rate ? <span className="num">{price(plan.base_rate)}</span> : null },
                    { label: t('rates.fields.min_los'), value: nightsLabel(plan.min_los) },
                    { label: t('rates.fields.max_los'), value: plan.default_max_los ? nightsLabel(plan.default_max_los) : '—' },
                    { label: t('rates.fields.booking_window'), value: window_ },
                    { label: t('rates.fields.applicable_to'), value: roomTypes },
                    { label: t('rates.columns.channels'), value: <ChannelIcons channels={plan.channels} /> },
                    { label: t('rates.fields.status'), value: <Badge status={plan.is_active ? 'active' : 'inactive'} /> },
                    { label: t('ui.created_on'), value: dateTime(plan.created_at) },
                    { label: t('ui.updated_on'), value: dateTime(plan.updated_at) },
                ]} />}
                {tab === 'rates' && <div className="stack">
                    <KeyValue items={[
                        { label: t('rates.fields.payment_type'), value: t(`rates.payment_types.${plan.payment_type}`) + (plan.deposit_value ? ` · ${Number(plan.deposit_value)}${plan.payment_type === 'deposit_percent' ? ' %' : ''}` : '') },
                        { label: t('rates.fields.min_los'), value: nightsLabel(plan.min_los) },
                        { label: t('rates.fields.max_los'), value: plan.default_max_los ? nightsLabel(plan.default_max_los) : '—' },
                        { label: t('rates.fields.booking_window'), value: window_ },
                    ]} />
                    {plan.cancellation_policy && <div className="info-box">
                        <div className="row-between"><strong>{plan.cancellation_policy.name}</strong><PolicyBadge refundable={plan.cancellation_policy.refundable} /></div>
                        {plan.cancellation_policy.description && <p className="text-sm muted" style={{ marginTop: 6 }}>{plan.cancellation_policy.description}</p>}
                        <PolicyRules rules={plan.cancellation_policy.rules} currency={currency()} />
                    </div>}
                    <p className="muted text-sm">{t('rates.daily_rates_hint')}</p>
                </div>}
                {tab === 'room_types' && (plan.products.length === 0 ? <p className="muted">{t('rates.none_linked')}</p> : (
                    <table className="table">
                        <thead><tr><th>{t('rooms.room_type')}</th><th className="num">{t('rates.fields.base_rate')}</th><th>{t('rates.fields.pricing')}</th></tr></thead>
                        <tbody>{plan.products.map((p) => (
                            <tr key={p.id}>
                                <td><span className="cell-main">{p.room_type_name}</span> {!p.is_active && <Badge size="sm" status="inactive" />}</td>
                                <td className="num">{price(p.base_price)}</td>
                                <td className="text-sm">{p.pricing_mode === 'manual' ? t('rooms.manual')
                                    : `${p.parent_rate_plan_name ?? ''} ${p.adjust_type === 'percent' ? `${Number(p.adjust_value) > 0 ? '+' : ''}${Number(p.adjust_value)} %` : `${Number(p.adjust_value) > 0 ? '+' : ''}${Number(p.adjust_value)}`}`}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                ))}
                {tab === 'channels' && <div className="stack">
                    <KeyValue items={[
                        { label: t('rates.channels.pms'), value: plan.sell_on_pms ? t('ui.yes') : t('ui.no') },
                        { label: t('rates.channels.booking_engine'), value: plan.sell_on_booking_engine ? t('ui.yes') : t('ui.no') },
                        { label: t('rates.channels.channels'), value: plan.sell_on_channels ? t('ui.yes') : t('ui.no') },
                    ]} />
                    <div className="info-box text-sm">
                        <Icon name="network" size={16} /> {plan.mapped_products > 0 ? t('rates.channels.mapped', { count: plan.mapped_products }) : t('rates.channels.not_mapped')}
                        <div className="muted" style={{ marginTop: 4 }}>{t('rates.channels.mapping_later')}</div>
                    </div>
                </div>}
            </div>
            <div className="sp-section">
                <h3>{t('ui.quick_actions')}</h3>
                <div className="action-grid">
                    {/* Daily rates are edited on the calendar, opened filtered to this rate plan. */}
                    {calendarUrl && <LinkButton variant="outline" icon="trending-up" href={`${calendarUrl}?rate_plan=${plan.id}`} title={t('rates.daily_rates_hint')}>{t('rates.quick.set_rates')}</LinkButton>}
                    {canCreate && <Button variant="outline" icon="copy" loading={busy} onClick={copy}>{t('rates.quick.copy')}</Button>}
                    {canUpdate && (plan.is_active
                        ? <Button variant="danger-soft" icon="pause" loading={busy} onClick={toggle} disabled={plan.is_default} title={plan.is_default ? t('rates.errors.default_cannot_deactivate') : undefined}>{t('rates.quick.deactivate')}</Button>
                        : <Button variant="outline" icon="check" loading={busy} onClick={toggle}>{t('rates.quick.activate')}</Button>)}
                    <Button variant="outline" icon="link" onClick={() => setTab('channels')}>{t('rates.quick.map_channels')}</Button>
                    <Button variant="outline" icon="history" onClick={() => setTab('history')}>{t('rates.quick.view_history')}</Button>
                </div>
                {tab === 'history' && <ul className="list-plain" style={{ marginTop: 12 }}>
                    {(plan.history ?? []).length === 0 && <li className="muted">{t('rooms.no_history')}</li>}
                    {(plan.history ?? []).map((h, i) => (
                        <li key={i} className="activity">
                            <span className="a-icon tone-slate"><Icon name="history" size={16} /></span>
                            <div className="grow"><div className="a-title">{h.label}</div><div className="a-sub">{h.user ?? '—'}</div></div>
                            <span className="a-time">{dateTime(h.at)}</span>
                        </li>
                    ))}
                </ul>}
            </div>
        </SidePanel>
    );
}
