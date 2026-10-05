import { useEffect, useState, type ReactNode } from 'react';
import { Badge, EmptyState, Icon, KeyValue, LinkButton, SidePanel, Stars, Tabs } from '@/components/ui';
import { date, number, time } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { PropertyDetail } from './types';

/**
 * Right-hand property details panel (Properties pages). Loads the property from
 * `endpoint` whenever it changes; `actions` renders the quick-action buttons.
 */
export function PropertyPanel({ endpoint, editUrl, onClose, actions, onLoaded }: {
    endpoint: string;
    editUrl?: string | null;
    onClose: () => void;
    actions?: (p: PropertyDetail) => ReactNode;
    onLoaded?: (p: PropertyDetail) => void;
}) {
    const [property, setProperty] = useState<PropertyDetail | null>(null);
    const [failed, setFailed] = useState<ApiError | null>(null);
    const [tab, setTab] = useState('overview');

    useEffect(() => {
        let alive = true;
        setProperty(null);
        setFailed(null);
        http.get<{ property: PropertyDetail }>(endpoint)
            .then((res) => { if (alive) { setProperty(res.property); onLoaded?.(res.property); } })
            .catch((e: ApiError) => alive && setFailed(e));
        return () => { alive = false; };
        // onLoaded is a callback from the parent; reloading only when the endpoint changes is intended.
    }, [endpoint]);

    if (failed) {
        return <SidePanel title={t('property.property')} onClose={onClose}><EmptyState icon="circle-alert" title={failed.message} /></SidePanel>;
    }
    if (!property) {
        return (
            <SidePanel title={t('ui.loading')} onClose={onClose}>
                <div className="sp-section stack">
                    <div className="skeleton" style={{ height: 160 }} />
                    <div className="skeleton" style={{ height: 18, width: '60%' }} />
                    <div className="skeleton" style={{ height: 120 }} />
                </div>
            </SidePanel>
        );
    }

    const p = property;
    const tabs = [
        { key: 'overview', label: t('property.tabs.overview') },
        { key: 'settings', label: t('property.tabs.settings') },
        { key: 'users', label: t('property.tabs.users') },
        { key: 'subscription', label: t('property.tabs.subscription') },
    ];

    return (
        <SidePanel title={p.name} onClose={onClose}>
            <div className="sp-hero">
                {p.image ? <img src={p.image} alt="" /> : <div style={{ height: '100%', display: 'grid', placeItems: 'center', color: 'var(--brand-600)' }}><Icon name="hotel" size={48} /></div>}
                {editUrl && <LinkButton size="sm" icon="pencil" href={editUrl}>{t('ui.edit')}</LinkButton>}
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                <div className="row" style={{ gap: 12 }}>
                    <h3 style={{ margin: 0, fontSize: 22 }}>{p.name}</h3>
                    <Badge status={p.status} />
                </div>
                <div className="row muted" style={{ gap: 10, marginTop: 4 }}>
                    <span className="num">{p.code}</span>
                    {p.star_rating ? <><span>|</span><Stars count={p.star_rating} /></> : null}
                </div>
                {p.tagline && <p className="muted" style={{ marginTop: 6 }}>{p.tagline}</p>}
            </div>
            <div style={{ padding: '0 20px' }}><Tabs items={tabs} active={tab} onChange={setTab} /></div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'overview' && (
                    <KeyValue items={[
                        { icon: 'hash', label: t('property.code'), value: p.code },
                        { icon: 'building-2', label: t('property.type'), value: p.type_label },
                        { icon: 'map-pin', label: t('property.address'), value: p.address || null },
                        { icon: 'clock', label: t('property.timezone'), value: p.timezone_label },
                        { icon: 'bed-double', label: t('property.total_rooms'), value: <span className="num">{number(p.rooms)}</span> },
                        { icon: 'tags', label: t('property.active_rate_plans'), value: <span className="num">{number(p.rate_plans)}</span> },
                        { icon: 'network', label: t('property.channels_connected'), value: <span className="num">0</span> },
                        { icon: 'user', label: t('property.contact_person'), value: p.contact_person ?? p.owner?.name },
                        { icon: 'mail', label: t('property.contact_email'), value: p.email ?? p.owner?.email },
                        { icon: 'phone', label: t('property.contact_phone'), value: p.phone ?? p.owner?.phone },
                    ]} />
                )}
                {tab === 'settings' && (
                    <KeyValue items={[
                        { icon: 'circle-dollar-sign', label: t('property.currency'), value: p.currency_code },
                        { icon: 'globe', label: t('property.default_language'), value: p.default_language.toUpperCase() },
                        { icon: 'calendar-days', label: t('property.date_format'), value: p.date_format },
                        { icon: 'clock', label: t('property.check_in_time'), value: time(p.check_in_time) },
                        { icon: 'clock', label: t('property.check_out_time'), value: time(p.check_out_time) },
                        { icon: 'file-text', label: t('property.tax_registration_no'), value: p.tax_registration_no },
                        { icon: 'calendar-check', label: t('property.business_date'), value: date(p.business_date) },
                    ]} />
                )}
                {tab === 'users' && (
                    <KeyValue items={[
                        { icon: 'users', label: t('property.active_users'), value: <span className="num">{number(p.users)}</span> },
                        { icon: 'user-check', label: t('property.sections.owner'), value: p.owner ? <>{p.owner.name}<br /><span className="muted text-sm">{p.owner.email}</span></> : null },
                    ]} />
                )}
                {tab === 'subscription' && (
                    <KeyValue items={[
                        { icon: 'credit-card', label: t('subscription.plan'), value: p.subscription.plan },
                        { icon: 'activity', label: t('ui.status_label'), value: <Badge status={p.subscription.status} /> },
                        { icon: 'calendar-x', label: t('subscription.ends_on'), value: date(p.subscription.ends_on) },
                        { icon: 'clock', label: t('subscription.days_left_label'), value: p.subscription.days_left !== null ? <span className="num">{p.subscription.days_left}</span> : null },
                    ]} />
                )}
            </div>
            {actions && <div className="sp-section"><div className="action-grid">{actions(p)}</div></div>}
        </SidePanel>
    );
}
