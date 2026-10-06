import { useEffect, useState } from 'react';
import { Badge, Button, ConfirmDialog, EmptyState, Icon, KeyValue, LinkButton, SidePanel, Tabs } from '@/components/ui';
import { dateTime, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, currency } from '../../_accommodation/shared';
import type { OfferDetail } from './types';

/** Offer detail panel (design offers.png): image, key facts, tabs Description / Conditions / Applicable Rooms / Channels / History. */
export function OfferPanel({ id, onClose }: { id: string; onClose: () => void }) {
    const [offer, setOffer] = useState<OfferDetail | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [tab, setTab] = useState('description');
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(false);

    useEffect(() => {
        let alive = true;
        setFailed(null);
        http.get<{ offer: OfferDetail }>(propertyApiUrl(`/offers/${id}`))
            .then((res) => alive && setOffer(res.offer))
            .catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id]);

    const title = t('offers.panel.title');
    if (failed) return <SidePanel title={title} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!offer || offer.id !== id) return <SidePanel title={title} onClose={onClose}><div className="sp-section stack"><div className="skeleton" style={{ height: 60 }} /><div className="skeleton" style={{ height: 260 }} /></div></SidePanel>;

    const cur = currency();
    const run = async (call: () => Promise<{ message: string; offer?: { id: string } }>, next?: (r: { offer?: { id: string } }) => string) => {
        setBusy(true);
        const res = await act(call);
        setBusy(false);
        if (res) window.location.href = next ? next(res) : window.location.href;
    };
    const toggle = () => run(() => http.post(propertyApiUrl(`/offers/${offer.id}/status`), { is_active: !offer.is_active }));
    const copy = () => run(() => http.post(propertyApiUrl(`/offers/${offer.id}/copy`)), (r) => propertyUrl(`/offers?selected=${r.offer?.id ?? ''}`));
    const remove = () => run(() => http.delete(propertyApiUrl(`/offers/${offer.id}`)), () => propertyUrl('/offers'));

    return (
        <SidePanel title={title} onClose={onClose}>
            <div className="sp-section row-between" style={{ borderTop: 0, paddingTop: 0 }}>
                <div className="row" style={{ flexWrap: 'wrap' }}>
                    <h3 style={{ fontSize: 20 }}>{offer.name}</h3>
                    <Badge status={offer.status}>{t(`offers.status.${offer.status}`)}</Badge>
                </div>
                <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/offers/${offer.id}/edit`)}>{t('offers.actions.edit')}</LinkButton>
            </div>
            <div className="sp-section offer-head" style={{ borderTop: 0, paddingTop: 0 }}>
                <div className="offer-image">
                    {offer.image_url ? <img src={offer.image_url} alt={offer.name} /> : <span className="muted text-sm"><Icon name="image" size={22} /><br />{t('offers.panel.no_image')}</span>}
                </div>
                <KeyValue items={[
                    { label: t('offers.panel.promotion_id'), value: offer.code },
                    { label: t('offers.panel.type'), value: offer.type_label },
                    { label: t('offers.panel.discount'), value: offer.discount_label },
                    { label: t('offers.panel.validity'), value: offer.validity_label },
                    { label: t('offers.panel.booking_window'), value: offer.booking_window },
                    { label: t('offers.panel.applicable_to'), value: offer.room_types.length ? offer.room_types.join(', ') : t('offers.all_room_types') },
                    { label: t('offers.panel.channels'), value: offer.channels_label },
                    { label: t('offers.panel.promo_code'), value: offer.promo_code ?? <span className="muted">{t('offers.panel.automatic')}</span> },
                ]} />
            </div>
            <div className="sp-section" style={{ borderTop: 0, paddingTop: 0 }}>
                <div className="offer-stats">
                    <div><span>{t('offers.panel.bookings')}</span><strong className="num">{offer.stats.bookings}</strong></div>
                    <div><span>{t('offers.panel.discount_given')}</span><strong className="num">{money(offer.stats.discount, cur)}</strong></div>
                    <div><span>{t('offers.panel.revenue')}</span><strong className="num">{money(offer.stats.revenue, cur)}</strong></div>
                </div>
            </div>
            <div className="panel-tabs" style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={['description', 'conditions', 'rooms', 'channels', 'history'].map((k) => ({ key: k, label: t(`offers.panel_tabs.${k}`) }))} />
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'description' && <div className="stack">
                    <p className={offer.description ? undefined : 'muted'} style={{ whiteSpace: 'pre-line' }}>{offer.description || t('offers.panel.no_description')}</p>
                    <h4>{t('offers.panel.highlights')}</h4>
                    <ul className="offer-highlights">{offer.highlights.map((h, i) => <li key={i}>{h}</li>)}</ul>
                </div>}
                {tab === 'conditions' && (offer.conditions.length === 0 && !offer.weekdays_label
                    ? <p className="muted">{t('offers.panel.no_conditions')}</p>
                    : <ul className="offer-highlights">
                        <li>{t('offers.panel.nights')}: {offer.weekdays_label}</li>
                        {offer.conditions.map((c, i) => <li key={i}>{c}</li>)}
                    </ul>)}
                {tab === 'rooms' && <KeyValue items={[
                    { label: t('offers.fields.room_types'), value: offer.room_types.length ? offer.room_types.join(', ') : t('offers.all_room_types') },
                    { label: t('offers.panel.rate_plans'), value: offer.rate_plans.length ? offer.rate_plans.join(', ') : t('offers.panel.all_rate_plans') },
                ]} />}
                {tab === 'channels' && <KeyValue items={[
                    { label: t('offers.channels.pms'), value: offer.channels.pms ? t('ui.yes') : t('ui.no') },
                    { label: t('offers.channels.booking_engine'), value: offer.channels.booking_engine ? t('ui.yes') : t('ui.no') },
                    { label: t('offers.panel.sources'), value: offer.channels.sources.length ? offer.channels.sources.join(', ') : t('offers.panel.all_sources') },
                ]} />}
                {tab === 'history' && <ul className="list-plain">
                    {offer.history.length === 0 && <li className="muted">{t('rooms.no_history')}</li>}
                    {offer.history.map((h, i) => (
                        <li key={i} className="activity">
                            <span className="a-icon tone-slate"><Icon name="history" size={16} /></span>
                            <div className="grow"><div className="a-title">{h.label}</div><div className="a-sub">{h.user ?? '—'}</div></div>
                            <span className="a-time">{dateTime(h.at)}</span>
                        </li>
                    ))}
                </ul>}
            </div>
            <div className="sp-section">
                <h3>{t('ui.quick_actions')}</h3>
                <div className="action-grid">
                    {offer.is_active
                        ? <Button variant="danger-soft" icon="pause" loading={busy} onClick={toggle}>{t('offers.actions.deactivate')}</Button>
                        : <Button variant="outline" icon="check" loading={busy} onClick={toggle}>{t('offers.actions.activate')}</Button>}
                    <Button variant="outline" icon="copy" loading={busy} onClick={copy}>{t('offers.actions.copy')}</Button>
                    <Button variant="outline" icon="trash" onClick={() => setConfirm(true)}>{t('offers.actions.delete')}</Button>
                </div>
            </div>
            <ConfirmDialog open={confirm} danger title={t('offers.actions.delete')} message={t('offers.actions.delete_confirm')} confirmLabel={t('offers.actions.delete')}
                busy={busy} onConfirm={remove} onClose={() => setConfirm(false)} />
        </SidePanel>
    );
}
