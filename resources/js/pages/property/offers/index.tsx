import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, Input, LinkButton, PageHeader, Pagination, PillTabs, RowMenu, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';
import { act } from '../_accommodation/shared';
import { OfferPanel } from './_components/OfferPanel';
import { OFFER_TYPES, type OfferRow } from './_components/types';

const TABS = ['all', 'active', 'scheduled', 'expired', 'room_discount', 'package', 'last_minute', 'early_bird'] as const;

interface Props {
    list: { rows: OfferRow[]; meta: PageMeta; counts: Record<(typeof TABS)[number], number> };
    filters: { q?: string; status?: string; type?: string; room_type?: string; channel?: string; tab?: string; selected?: string };
    options: { room_types: Option[] };
}

/** Offers & promotions (design offers.png): filters, status/type tabs, table and detail panel. */
function OffersPage({ list, filters, options }: Props) {
    const [selected, setSelected] = useQueryState('selected', filters.selected || list.rows[0]?.id || '');
    const [q, setQ] = useState(filters.q ?? '');
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const tab = filters.tab || 'all';
    const hasFilters = !!(filters.q || filters.status || filters.type || filters.room_type || filters.channel);

    const toggle = async (r: OfferRow) => {
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`/offers/${r.id}/status`), { is_active: !r.is_active }));
        if (res) window.location.reload();
    };
    const copy = async (r: OfferRow) => {
        const res = await act(() => http.post<{ message: string; offer: { id: string } }>(propertyApiUrl(`/offers/${r.id}/copy`)));
        if (res) window.location.href = propertyUrl(`/offers?selected=${res.offer.id}`);
    };
    const exportUrl = () => {
        const params = new URLSearchParams(window.location.search);
        params.delete('selected');
        return propertyApiUrl(`/offers/export${params.toString() ? `?${params}` : ''}`);
    };

    const columns: Column<OfferRow>[] = [
        { key: 'code', header: t('offers.columns.id'), sortable: true, render: (r) => <a className="link nowrap" href={propertyUrl(`/offers?selected=${r.id}`)} onClick={(e) => { e.preventDefault(); setSelected(r.id); }}>{r.code}</a> },
        { key: 'name', header: t('offers.columns.name'), sortable: true, className: 'th-wrap', render: (r) => <><span className="cell-main">{r.name}</span>{r.promo_code && <span className="cell-sub block">{r.promo_code}</span>}</> },
        { key: 'type', header: t('offers.columns.type'), className: 'hide-with-panel', render: (r) => r.type_label },
        { key: 'discount', header: t('offers.columns.discount'), className: 'th-wrap', render: (r) => r.discount_label },
        { key: 'stay_from', header: t('offers.columns.validity'), sortable: true, className: 'th-wrap', render: (r) => <span className="nowrap">{r.validity_label}</span> },
        { key: 'status', header: t('offers.columns.status'), sortable: true, render: (r) => <Badge size="sm" status={r.status}>{t(`offers.status.${r.status}`)}</Badge> },
        { key: 'channels', header: t('offers.columns.channels'), className: 'hide-with-panel', render: (r) => r.channels_label },
        {
            key: 'actions', header: t('offers.columns.actions'), className: 'col-actions', render: (r) => (
                <div className="row nowrap" style={{ gap: 6 }}>
                    <LinkButton size="sm" variant="outline" icon="pencil" className="offer-edit" title={t('offers.actions.edit')} href={propertyUrl(`/offers/${r.id}/edit`)}><span className="btn-label">{t('offers.actions.edit')}</span></LinkButton>
                    <RowMenu items={[
                        { label: t('offers.actions.view'), icon: 'eye', onClick: () => setSelected(r.id) },
                        { label: t(r.is_active ? 'offers.actions.deactivate' : 'offers.actions.activate'), icon: r.is_active ? 'pause' : 'check', onClick: () => toggle(r) },
                        { label: t('offers.actions.copy'), icon: 'copy', onClick: () => copy(r) },
                    ]} />
                </div>
            ),
        },
    ];

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('offers.title')} description={t('offers.description')} actions={<>
                    <LinkButton variant="outline" icon="download" href={exportUrl()} download>{t('offers.export')}</LinkButton>
                    <LinkButton variant="primary" icon="plus" href={propertyUrl('/offers/new')}>{t('offers.add')}</LinkButton>
                </>} />

                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('offers.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('offers.filters.status')} value={filters.status ?? ''} placeholder={t('offers.all_statuses')}
                        options={['active', 'scheduled', 'expired', 'inactive'].map((s) => ({ value: s, label: t(`offers.status.${s}`) }))} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                    <Select label={t('offers.filters.type')} value={filters.type ?? ''} placeholder={t('offers.all_types')}
                        options={OFFER_TYPES.map((s) => ({ value: s, label: t(`offers.types.${s}`) }))} onChange={(e) => navigateWithQuery({ type: e.target.value })} />
                    <Select label={t('offers.filters.room_type')} value={filters.room_type ?? ''} placeholder={t('offers.all_room_types')} options={options.room_types}
                        onChange={(e) => navigateWithQuery({ room_type: e.target.value })} />
                    <Select label={t('offers.filters.channel')} value={filters.channel ?? ''} placeholder={t('offers.all_channels')}
                        options={['pms', 'booking_engine'].map((c) => ({ value: c, label: t(`offers.channels.${c}`) }))} onChange={(e) => navigateWithQuery({ channel: e.target.value })} />
                    {hasFilters && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, status: null, type: null, room_type: null, channel: null })}>{t('ui.reset')}</Button>}
                </form>

                <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'all' ? null : k })}
                    items={TABS.map((k) => ({ key: k, label: t(`offers.tabs.${k}`), count: list.counts[k] }))} />

                <DataTable columns={columns} rows={list.rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                    selectable selected={checked} onSelect={setChecked}
                    empty={!hasFilters && list.counts.all === 0
                        ? <EmptyState icon="badge-percent" title={t('offers.none')} text={t('offers.none_hint')} action={<LinkButton variant="primary" icon="plus" href={propertyUrl('/offers/new')}>{t('offers.add')}</LinkButton>} />
                        : undefined} />
                <Pagination meta={list.meta} label={t('offers.plural')} />
            </div>
            {selected && <OfferPanel id={selected} onClose={() => setSelected(null)} />}
        </div>
    );
}

createPage(OffersPage);
