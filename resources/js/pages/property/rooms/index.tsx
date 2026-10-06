import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, Icon, Input, PageHeader, Pagination, PillTabs, RowMenu, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { downloadCsv } from '@/lib/csv';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { propertyApiUrl } from '@/lib/page';
import { toast } from '@/components/ui';
import { t } from '@/lib/i18n';
import { useQueryState } from '@/lib/use';
import { AddRoomsModal, BlockRoomModal, EditRoomModal } from './_components/RoomDialogs';
import { RoomPanel } from './_components/RoomPanel';
import type { RoomDetail, RoomRow } from './_components/types';

const TABS = ['all', 'available', 'occupied', 'out_of_service', 'out_of_order', 'housekeeping', 'due_cleaning'] as const;

interface Props {
    list: { rows: RoomRow[]; meta: PageMeta; counts: Record<string, number>; today: string };
    filters: { q?: string; room_type?: string; status?: string; floor?: string; tab?: string; selected?: string };
    options: { room_types: Option[]; floors: Option[]; block_types: Option[] };
    can: { create: boolean; update: boolean; housekeeping: boolean };
}

/** PMS rooms (design: rooms-pms.png): list with status tabs and a room detail panel. */
function RoomsPage({ list, filters, options, can }: Props) {
    const [selected, setSelected] = useQueryState('selected', filters.selected || list.rows[0]?.id || '');
    const [q, setQ] = useState(filters.q ?? '');
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<RoomDetail | null>(null);
    const [blocking, setBlocking] = useState<RoomDetail | null>(null);
    const [version, setVersion] = useState(0);
    const [rows, setRows] = useState(list.rows);
    const tab = filters.tab || 'all';

    const refreshRow = (r: RoomDetail) => setRows((list) => list.map((x) => (x.id === r.id ? { ...x, status: r.status, housekeeping_status: r.housekeeping_status, is_active: r.is_active } : x)));
    const openEdit = async (id: string, block = false) => {
        setSelected(id);
        try {
            const res = await http.get<{ room: RoomDetail }>(propertyApiUrl(`/rooms/${id}`));
            if (block) setBlocking(res.room); else setEditing(res.room);
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        }
    };
    const reloadWith = (id?: string) => navigateWithQuery({ selected: id ?? selected }, false);

    const exportCsv = () => downloadCsv('rooms.csv',
        [t('rooms.columns.room_no'), t('rooms.fields.room_type'), t('rooms.columns.floor'), t('rooms.room_status'), t('rooms.columns.housekeeping'), t('rooms.columns.current_guest')],
        rows.map((r) => [r.name, r.room_type.name, r.floor, t(`ui.status.${r.status}`), t(`ui.status.${r.housekeeping_status}`), r.guest ? `${r.guest.name} (${r.guest.reservation})` : '']));

    const columns: Column<RoomRow>[] = [
        { key: 'name', header: t('rooms.columns.room_no'), sortable: true, render: (r) => <span className="cell-main num">{r.name}</span> },
        { key: 'room_type', header: t('rooms.fields.room_type'), sortable: true, render: (r) => `${r.room_type.name} (${r.room_type.code})` },
        { key: 'floor', header: t('rooms.columns.floor'), sortable: true, align: 'center', render: (r) => r.floor ?? '—' },
        { key: 'status', header: t('rooms.room_status'), render: (r) => <Badge status={r.status} /> },
        {
            key: 'housekeeping', header: t('rooms.columns.housekeeping'), sortable: true, className: 'hide-with-panel', render: (r) => (
                <span className="row" title={t('rooms.fields.housekeeping_status')}><Badge size="sm" status={r.housekeeping_status} /></span>
            ),
        },
        {
            key: 'guest', header: t('rooms.columns.current_guest'), render: (r) => r.guest
                ? <span className="row"><Icon name="user-round" size={18} /><span><span className="cell-main">{r.guest.name}</span><br /><span className="cell-sub num">{r.guest.reservation}</span></span></span>
                : <span className="muted">—</span>,
        },
        {
            key: 'actions', header: t('rooms.columns.actions'), className: 'col-actions', render: (r) => (
                <span className="row" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
                    {can.update && <Button size="sm" variant="outline" icon="pencil" onClick={() => openEdit(r.id)}>{t('ui.edit')}</Button>}
                    <RowMenu items={[
                        { label: t('ui.view'), icon: 'eye', onClick: () => setSelected(r.id) },
                        ...(can.update ? [{ label: t('rooms.mark_out_of_service'), icon: 'ban', danger: true, onClick: () => openEdit(r.id, true) }] : []),
                    ]} />
                </span>
            ),
        },
    ];

    const hasFilters = !!(filters.q || filters.room_type || filters.status || filters.floor);
    const roomTypes = options.room_types.map((o) => ({ value: o.value, label: o.label }));

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('rooms.rooms_title')} description={t('rooms.rooms_desc')} actions={<>
                    <Button variant="secondary" icon="download" onClick={exportCsv} disabled={rows.length === 0}>{t('ui.export')}</Button>
                    {can.create && <Button variant="primary" icon="plus" onClick={() => setAdding(true)} disabled={roomTypes.length === 0}>{t('rooms.add_room')}</Button>}
                </>} />

                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('rooms.search_rooms')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('rooms.fields.room_type')} value={filters.room_type ?? ''} placeholder={t('rooms.all_room_types')} options={roomTypes} onChange={(e) => navigateWithQuery({ room_type: e.target.value })} />
                    <Select label={t('rooms.room_status')} value={filters.status ?? ''} placeholder={t('rooms.all_statuses')}
                        options={['available', 'occupied', 'out_of_service', 'out_of_order', 'inactive'].map((s) => ({ value: s, label: t(`ui.status.${s}`) }))}
                        onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                    <Select label={t('rooms.columns.floor')} value={filters.floor ?? ''} placeholder={t('rooms.all_floors')} options={options.floors} onChange={(e) => navigateWithQuery({ floor: e.target.value })} />
                    {hasFilters && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, room_type: null, status: null, floor: null })}>{t('ui.reset')}</Button>}
                </form>

                <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'all' ? null : k })}
                    items={TABS.map((k) => ({ key: k, label: t(`rooms.tabs.${k}`), count: list.counts[k] ?? 0 }))} />

                <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                    selectable selected={checked} onSelect={setChecked}
                    empty={!hasFilters && tab === 'all' && list.counts.all === 0
                        ? <EmptyState icon="door-open" title={t('rooms.no_rooms')} text={t('rooms.no_rooms_hint')} />
                        : undefined} />
                <Pagination meta={list.meta} label={t('rooms.room_plural')} />
            </div>

            {selected && <RoomPanel id={selected} version={version} canUpdate={can.update} canHousekeeping={can.housekeeping || can.update}
                onClose={() => setSelected(null)} onEdit={setEditing} onBlock={setBlocking} onChanged={refreshRow} />}
            {adding && <AddRoomsModal roomTypes={roomTypes} initialType={filters.room_type ?? ''} onClose={() => setAdding(false)} onSaved={(id) => reloadWith(id)} />}
            {editing && <EditRoomModal room={editing} roomTypes={roomTypes} onClose={() => setEditing(null)} onSaved={() => reloadWith(editing.id)} />}
            {blocking && <BlockRoomModal room={blocking} blockTypes={options.block_types} today={list.today}
                onClose={() => setBlocking(null)} onSaved={() => { setBlocking(null); setVersion((v) => v + 1); reloadWith(blocking.id); }} />}
        </div>
    );
}

createPage(RoomsPage);
