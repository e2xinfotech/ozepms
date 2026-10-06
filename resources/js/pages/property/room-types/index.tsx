import { useState } from 'react';
import { Badge, Button, ConfirmDialog, DataTable, EmptyState, Icon, Input, LinkButton, PageHeader, Pagination, PillTabs, RowMenu, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { downloadCsv } from '@/lib/csv';
import { http, navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, labelOf, Occupancy } from '../_accommodation/shared';

interface ProductOption { id: string; rate_plan: string; label: string; meal_plan: string | null; is_default: boolean }
interface Row {
    id: string; name: string; code: string; category: string | null; description: string | null; image: string | null;
    base_adults: number; max_adults: number; max_children: number; max_occupancy: number;
    total_rooms: number; active_rooms: number; is_active: boolean; default_product: string | null; products: ProductOption[];
}
interface Props {
    list: { rows: Row[]; meta: PageMeta; counts: { all: number; active: number; inactive: number } };
    filters: { q?: string; status?: string; category?: string; meal_plan?: string };
    options: { categories: Option[]; meal_plans: Option[] };
    can: { create: boolean; update: boolean };
}

/** Room types list (design: room-types.png). */
function RoomTypesPage({ list, filters, options, can }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const [toggle, setToggle] = useState<Row | null>(null);
    const [busy, setBusy] = useState(false);

    const setDefault = async (row: Row, productId: string) => {
        await act(() => http.put<{ message: string }>(propertyApiUrl(`/room-types/${row.id}/default-rate-plan`), { product_id: productId }));
    };

    const changeStatus = async () => {
        if (!toggle) return;
        setBusy(true);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`/room-types/${toggle.id}/status`), { is_active: !toggle.is_active }));
        setBusy(false);
        setToggle(null);
        if (res) window.location.reload();
    };

    const exportCsv = () => downloadCsv('room-types.csv',
        [t('rooms.columns.room_type'), t('rooms.columns.code'), t('rooms.columns.category'), t('rooms.columns.base_occupancy'), t('rooms.columns.max_occupancy'),
            t('rooms.columns.total_rooms'), t('rooms.columns.active_rooms'), t('rooms.columns.default_rate_plan'), t('rooms.columns.status')],
        list.rows.map((r) => [r.name, r.code, labelOf(options.categories, r.category), r.base_adults, r.max_occupancy, r.total_rooms, r.active_rooms,
            r.products.find((p) => p.id === r.default_product)?.label ?? '', t(`ui.status.${r.is_active ? 'active' : 'inactive'}`)]));

    const columns: Column<Row>[] = [
        {
            key: 'name', header: t('rooms.columns.room_type'), sortable: true, className: 'rt-name-cell', render: (r) => (
                <div className="media-cell">
                    {r.image ? <img className="rt-thumb" src={r.image} alt="" /> : <span className="rt-thumb"><Icon name="bed-double" size={22} /></span>}
                    <div>
                        <a className="cell-main" href={can.update ? propertyUrl(`/room-types/${r.id}/edit`) : undefined}>{r.name} ({r.code})</a>
                        {r.description && <div className="rt-desc">{r.description}</div>}
                    </div>
                </div>
            ),
        },
        { key: 'code', header: t('rooms.columns.code'), sortable: true, className: 'col-code', render: (r) => r.code },
        { key: 'category', header: t('rooms.columns.category'), sortable: true, render: (r) => labelOf(options.categories, r.category) },
        { key: 'base', header: t('rooms.columns.base_occupancy'), align: 'center', className: 'th-wrap', render: (r) => <Occupancy adults={r.base_adults} /> },
        { key: 'max', header: t('rooms.columns.max_occupancy'), align: 'center', className: 'th-wrap', render: (r) => <Occupancy adults={r.max_occupancy} /> },
        { key: 'total', header: t('rooms.columns.total_rooms'), align: 'right', className: 'th-wrap', render: (r) => r.total_rooms },
        { key: 'active', header: t('rooms.columns.active_rooms'), align: 'right', className: 'th-wrap', render: (r) => <a href={propertyUrl(`/rooms?room_type=${r.id}`)} title={t('rooms.manage_rooms')}>{r.active_rooms}</a> },
        {
            key: 'default_rate_plan', header: t('rooms.columns.default_rate_plan'), className: 'th-wrap', render: (r) => r.products.length === 0
                ? <span className="muted">{t('rooms.no_rate_plan')}</span>
                : <span onClick={(e) => e.stopPropagation()}>
                    <Select size="sm" className="table-select" aria-label={t('rooms.columns.default_rate_plan')} disabled={!can.update}
                        defaultValue={r.default_product ?? ''} options={r.products.map((p) => ({ value: p.id, label: p.label }))}
                        onChange={(e) => setDefault(r, e.target.value)} />
                </span>,
        },
        { key: 'status', header: t('rooms.columns.status'), sortable: true, render: (r) => <Badge status={r.is_active ? 'active' : 'inactive'} /> },
        {
            key: 'actions', header: t('rooms.columns.actions'), className: 'col-actions', render: (r) => (
                <span className="row" style={{ justifyContent: 'flex-end' }}>
                    {can.update && <LinkButton size="sm" variant="outline" icon="pencil" href={propertyUrl(`/room-types/${r.id}/edit`)}>{t('ui.edit')}</LinkButton>}
                    <RowMenu items={[
                        { label: t('rooms.manage_rooms'), icon: 'door-open', href: propertyUrl(`/rooms?room_type=${r.id}`) },
                        ...(can.update ? [{ label: r.is_active ? t('rooms.deactivate') : t('rooms.activate'), icon: r.is_active ? 'pause' : 'check', danger: r.is_active, onClick: () => setToggle(r) }] : []),
                    ]} />
                </span>
            ),
        },
    ];

    const hasFilters = !!(filters.q || filters.status || filters.category || filters.meal_plan);

    return (
        <div className="content">
            <PageHeader title={t('rooms.room_types_title')} description={t('rooms.room_types_desc')} actions={<>
                <LinkButton variant="secondary" icon="sparkles" href={propertyUrl('/amenities')}>{t('rooms.manage_amenities')}</LinkButton>
                <Button variant="secondary" icon="upload" onClick={exportCsv} disabled={list.rows.length === 0}>{t('ui.export')}</Button>
                {can.create && <LinkButton variant="primary" icon="plus" href={propertyUrl('/room-types/new')}>{t('rooms.add_room_type')}</LinkButton>}
            </>} />

            <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('rooms.search_room_types')} value={q} onChange={(e) => setQ(e.target.value)} />
                <Select label={t('rooms.columns.status')} value={filters.status ?? ''} placeholder={t('rooms.all_statuses')}
                    options={['active', 'inactive'].map((s) => ({ value: s, label: t(`ui.status.${s}`) }))} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                <Select label={t('rates.columns.meal_plan')} value={filters.meal_plan ?? ''} placeholder={t('rooms.all_meal_plans')} options={options.meal_plans} onChange={(e) => navigateWithQuery({ meal_plan: e.target.value })} />
                <Select label={t('rooms.columns.category')} value={filters.category ?? ''} placeholder={t('rooms.all_categories')} options={options.categories} onChange={(e) => navigateWithQuery({ category: e.target.value })} />
                {hasFilters && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, status: null, category: null, meal_plan: null })}>{t('ui.reset')}</Button>}
            </form>

            <PillTabs active={filters.status || 'all'} onChange={(k) => navigateWithQuery({ status: k === 'all' ? null : k })} items={[
                { key: 'all', label: t('ui.all'), count: list.counts.all },
                { key: 'active', label: t('ui.status.active'), count: list.counts.active },
                { key: 'inactive', label: t('ui.status.inactive'), count: list.counts.inactive },
            ]} />

            <div className="rt-table">
                <DataTable columns={columns} rows={list.rows} rowKey={(r) => r.id} selectable selected={checked} onSelect={setChecked}
                    empty={!hasFilters && list.counts.all === 0
                        ? <EmptyState icon="bed-double" title={t('rooms.no_room_types')} text={t('rooms.no_room_types_hint')}
                            action={can.create && <LinkButton variant="primary" icon="plus" href={propertyUrl('/room-types/new')}>{t('rooms.add_room_type')}</LinkButton>} />
                        : undefined} />
            </div>
            <Pagination meta={list.meta} label={t('rooms.room_type_plural')} />

            <ConfirmDialog open={!!toggle} busy={busy} danger={toggle?.is_active}
                title={toggle?.is_active ? t('rooms.deactivate') : t('rooms.activate')}
                message={toggle?.is_active ? t('rooms.deactivate_room_type_confirm', { name: toggle?.name ?? '' }) : toggle?.name}
                confirmLabel={toggle?.is_active ? t('rooms.deactivate') : t('rooms.activate')}
                onConfirm={changeStatus} onClose={() => setToggle(null)} />
        </div>
    );
}

createPage(RoomTypesPage);
