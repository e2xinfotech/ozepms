import { useEffect, useState } from 'react';
import { Badge, Button, Dropdown, EmptyState, Icon, KeyValue, SidePanel, Tabs } from '@/components/ui';
import { date, dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, Carousel } from '../../_accommodation/shared';
import type { RoomDetail } from './types';

/** Right-hand panel of the Rooms page (design: rooms-pms.png). */
export function RoomPanel({ id, version, canUpdate, canHousekeeping, onClose, onEdit, onBlock, onChanged }: {
    id: string; version: number; canUpdate: boolean; canHousekeeping: boolean;
    onClose: () => void; onEdit: (r: RoomDetail) => void; onBlock: (r: RoomDetail) => void; onChanged: (r: RoomDetail) => void;
}) {
    const [room, setRoom] = useState<RoomDetail | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [tab, setTab] = useState('details');

    useEffect(() => {
        let alive = true;
        setFailed(null);
        http.get<{ room: RoomDetail }>(propertyApiUrl(`/rooms/${id}`))
            .then((res) => alive && setRoom(res.room))
            .catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id, version]);

    if (failed) return <SidePanel title={t('rooms.room')} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!room || room.id !== id) return <SidePanel title={t('ui.loading')} onClose={onClose}><div className="sp-section stack"><div className="skeleton" style={{ height: 160 }} /><div className="skeleton" style={{ height: 220 }} /></div></SidePanel>;

    const housekeeping = async (status: string) => {
        const res = await act(() => http.post<{ message: string; room: RoomDetail }>(propertyApiUrl(`/rooms/${room.id}/housekeeping`), { housekeeping_status: status }));
        if (res) { setRoom(res.room); onChanged(res.room); }
    };
    const release = async (blockId: number) => {
        const res = await act(() => http.delete<{ message: string; room: RoomDetail }>(propertyApiUrl(`/rooms/${room.id}/blocks/${blockId}`)));
        if (res) { setRoom(res.room); onChanged(res.room); }
    };

    return (
        <SidePanel title={`${t('rooms.room')} ${room.name}`} onClose={onClose}
            headerExtra={canUpdate && <Button size="sm" variant="outline" icon="pencil" onClick={() => onEdit(room)}>{t('ui.edit')}</Button>}>
            <div className="panel-split">
                <Carousel images={room.images} alt={room.room_type.name} />
                <KeyValue items={[
                    { label: t('rooms.fields.room_type'), value: `${room.room_type.name} (${room.room_type.code})` },
                    { label: t('rooms.fields.floor'), value: room.floor },
                    { label: t('rooms.room_status'), value: <Badge status={room.status} /> },
                    { label: t('rooms.columns.current_guest'), value: room.guest?.name },
                    { label: t('rooms.fields.reservation'), value: room.guest?.reservation },
                ]} />
            </div>
            <div className="panel-tabs" style={{ padding: '6px 20px 0' }}>
                <Tabs active={tab} onChange={setTab} items={[
                    { key: 'details', label: t('rooms.panel_tabs.details') },
                    { key: 'amenities', label: t('rooms.panel_tabs.amenities') },
                    { key: 'images', label: t('rooms.panel_tabs.images') },
                    { key: 'notes', label: t('rooms.panel_tabs.notes') },
                    { key: 'history', label: t('rooms.panel_tabs.history') },
                ]} />
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'details' && <div className="stack">
                    <KeyValue wide items={[
                        { label: t('rooms.fields.room_number'), value: room.name },
                        { label: t('rooms.fields.room_type'), value: room.room_type.name },
                        { label: t('rooms.fields.building'), value: room.building },
                        { label: t('rooms.columns.max_occupancy'), value: room.max_occupancy },
                        { label: t('rooms.fields.extra_bed_allowed'), value: room.extra_bed_allowed ? t('ui.yes') : t('ui.no') },
                        { label: t('rooms.fields.default_rate_plan'), value: room.default_rate_plan },
                        { label: t('rooms.fields.housekeeping_status'), value: <Badge status={room.housekeeping_status} /> },
                        { label: t('rooms.fields.last_cleaned'), value: room.last_cleaned_at ? dateTime(room.last_cleaned_at) : t('ui.never') },
                        { label: t('rooms.fields.next_cleaning'), value: room.next_cleaning_at ? dateTime(room.next_cleaning_at) : '—' },
                        { label: t('rooms.fields.in_maintenance'), value: room.in_maintenance ? t('ui.yes') : t('ui.no') },
                    ]} />
                    <div>
                        <h3 style={{ fontSize: 16, marginBottom: 8 }}>{t('rooms.blocks')}</h3>
                        {room.blocks.length === 0 ? <p className="muted text-sm">{t('rooms.no_blocks')}</p> : (
                            <ul className="list-plain">
                                {room.blocks.map((b) => (
                                    <li key={b.id} className="activity">
                                        <span className={`a-icon tone-${b.type === 'out_of_order' ? 'red' : 'orange'}`}><Icon name="ban" size={16} /></span>
                                        <div className="grow">
                                            <div className="a-title">{t(`rooms.block_types.${b.type}`)}</div>
                                            <div className="a-sub num">{date(b.start_date)} – {date(b.end_date)}{b.reason ? ` · ${b.reason}` : ''}</div>
                                        </div>
                                        {canUpdate && <Button size="sm" variant="ghost" onClick={() => release(b.id)}>{t('rooms.release_block')}</Button>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>}
                {tab === 'amenities' && (room.amenities.length === 0 ? <p className="muted">{t('rooms.no_amenities')}</p> : (
                    <div className="amenity-chips">{room.amenities.map((a) => <span key={a.code} className="chip" title={a.source === 'room' ? t('amenities.room_only') : t('amenities.from_room_type')}><Icon name={a.icon ?? 'check'} size={15} />{a.label}{a.source === 'room' && <span className="amenity-extra">{t('amenities.room_only')}</span>}</span>)}</div>
                ))}
                {tab === 'images' && (room.images.length === 0 ? <p className="muted">{t('rooms.no_images')}</p> : (
                    <div className="image-grid">{room.images.map((i) => <div key={i.id} className="image-tile"><img src={i.url} alt={i.alt ?? ''} /></div>)}</div>
                ))}
                {tab === 'notes' && <p className={room.notes ? undefined : 'muted'} style={{ whiteSpace: 'pre-wrap' }}>{room.notes || t('rooms.no_notes')}</p>}
                {tab === 'history' && (room.history.length === 0 ? <p className="muted">{t('rooms.no_history')}</p> : (
                    <ul className="list-plain">
                        {room.history.map((h, i) => (
                            <li key={i} className="activity">
                                <span className="a-icon tone-slate"><Icon name="history" size={16} /></span>
                                <div className="grow"><div className="a-title">{h.label}</div><div className="a-sub">{h.user ?? '—'}</div></div>
                                <span className="a-time">{dateTime(h.at)}</span>
                            </li>
                        ))}
                    </ul>
                ))}
            </div>
            <div className="panel-actions">
                {canUpdate && <Button variant="danger-soft" icon="ban" onClick={() => onBlock(room)} disabled={!room.is_active}>{t('rooms.mark_out_of_service')}</Button>}
                {canHousekeeping && (
                    <Dropdown align="right" trigger={(toggle) => <Button variant="outline" icon="clipboard-check" block onClick={toggle}>{t('rooms.assign_housekeeping')}</Button>}
                        items={[
                            { label: t('rooms.mark_clean'), icon: 'check', onClick: () => housekeeping('clean'), active: room.housekeeping_status === 'clean' },
                            { label: t('rooms.mark_dirty'), icon: 'spray-can', onClick: () => housekeeping('dirty'), active: room.housekeeping_status === 'dirty' },
                            { label: t('rooms.mark_inspected'), icon: 'clipboard-check', onClick: () => housekeeping('inspected'), active: room.housekeeping_status === 'inspected' },
                        ]} />
                )}
            </div>
        </SidePanel>
    );
}
