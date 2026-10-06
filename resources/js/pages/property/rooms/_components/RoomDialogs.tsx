import { useState } from 'react';
import { Alert, Button, Input, Modal, Segmented, Select, Textarea, Toggle, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from '../../_accommodation/shared';
import type { RoomDetail } from './types';

/** Add one room or several rooms at once (range, prefix + sequence, quantity). */
export function AddRoomsModal({ roomTypes, initialType, onClose, onSaved }: {
    roomTypes: Option[]; initialType: string; onClose: () => void; onSaved: (id?: string) => void;
}) {
    const [mode, setMode] = useState<'single' | 'range' | 'sequence' | 'quantity'>('single');
    const [form, setForm] = useState({ room_type_id: initialType || String(roomTypes[0]?.value ?? ''), name: '', floor: '', building: '', range: '', prefix: '', start: '1', count: '5', quantity: '5' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = mode === 'single'
            ? await act(() => http.post<{ message: string; room: RoomDetail }>(propertyApiUrl('/rooms'), {
                room_type_id: form.room_type_id, name: form.name, floor: form.floor || null, building: form.building || null,
            }), setError)
            : await act(() => http.post<{ message: string }>(propertyApiUrl('/rooms/bulk'), {
                room_type_id: form.room_type_id, mode, range: form.range || null, prefix: form.prefix || null, start: form.start || null,
                count: form.count ? Number(form.count) : null, quantity: form.quantity ? Number(form.quantity) : null, floor: form.floor || null,
            }), setError);
        setBusy(false);
        if (res) onSaved('room' in res ? (res as { room: RoomDetail }).room.id : undefined);
    };

    const generic = error && Object.keys(error.fields).filter((k) => !['room_type_id', 'name', 'range', 'start', 'count', 'quantity', 'floor'].includes(k)).length > 0;

    return (
        <Modal open size="lg" title={t('rooms.add_room')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="plus" loading={busy} onClick={save}>{t('rooms.add_room')}</Button>
        </>}>
            <div className="stack">
                <Segmented active={mode} onChange={(k) => setMode(k as typeof mode)} items={[
                    { key: 'single', label: t('rooms.add_room') },
                    { key: 'range', label: t('rooms.bulk_modes.range') },
                    { key: 'sequence', label: t('rooms.bulk_modes.sequence') },
                    { key: 'quantity', label: t('rooms.bulk_modes.quantity') },
                ]} />
                {generic && <Alert tone="danger">{Object.values(error!.fields)[0]?.[0]}</Alert>}
                <div className="form-grid">
                    <Select fieldClass="span-6" label={t('rooms.fields.room_type')} required value={form.room_type_id} options={roomTypes}
                        onChange={(e) => set('room_type_id', e.target.value)} error={fieldError(error, 'room_type_id')} />
                    <Input fieldClass="span-3" label={t('rooms.fields.floor')} optional value={form.floor} maxLength={10} onChange={(e) => set('floor', e.target.value)} error={fieldError(error, 'floor')} />
                    {mode === 'single' && <>
                        <Input fieldClass="span-3" label={t('rooms.fields.building')} optional value={form.building} maxLength={40} onChange={(e) => set('building', e.target.value)} />
                        <Input fieldClass="span-6" label={t('rooms.fields.room_name')} required autoFocus value={form.name} maxLength={30} placeholder="101" onChange={(e) => set('name', e.target.value)} error={fieldError(error, 'name')} />
                    </>}
                    {mode === 'range' && <Input fieldClass="span-6" label={t('rooms.bulk.range')} required value={form.range} placeholder="101-120" hint={t('rooms.bulk.range_hint')}
                        onChange={(e) => set('range', e.target.value)} error={fieldError(error, 'range') ?? fieldError(error, 'count')} />}
                    {mode === 'sequence' && <>
                        <Input fieldClass="span-4" label={t('rooms.bulk.prefix')} optional value={form.prefix} placeholder="VIL-" maxLength={20} onChange={(e) => set('prefix', e.target.value)} />
                        <Input fieldClass="span-4" label={t('rooms.bulk.start')} required value={form.start} inputMode="numeric" onChange={(e) => set('start', e.target.value)} error={fieldError(error, 'start')} />
                        <Input fieldClass="span-4" type="number" min={1} max={500} label={t('rooms.bulk.count')} required value={form.count} onChange={(e) => set('count', e.target.value)} error={fieldError(error, 'count')} />
                    </>}
                    {mode === 'quantity' && <Input fieldClass="span-4" type="number" min={1} max={500} label={t('rooms.fields.rooms_quantity')} required value={form.quantity}
                        onChange={(e) => set('quantity', e.target.value)} error={fieldError(error, 'quantity')} />}
                </div>
                {(fieldError(error, 'units') || fieldError(error, 'mode')) && <Alert tone="danger">{fieldError(error, 'units') ?? fieldError(error, 'mode')}</Alert>}
            </div>
        </Modal>
    );
}

/** Edit name, floor, room type, notes and active state. */
export function EditRoomModal({ room, roomTypes, onClose, onSaved }: { room: RoomDetail; roomTypes: Option[]; onClose: () => void; onSaved: () => void }) {
    const [form, setForm] = useState({ name: room.name, floor: room.floor ?? '', building: room.building ?? '', notes: room.notes ?? '', room_type_id: room.room_type.id, is_active: room.is_active });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.put<{ message: string }>(propertyApiUrl(`/rooms/${room.id}`), {
            ...form, floor: form.floor || null, building: form.building || null, notes: form.notes || null,
        }), setError);
        setBusy(false);
        if (res) onSaved();
    };

    return (
        <Modal open title={`${t('rooms.edit_room')} ${room.name}`} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save_changes')}</Button>
        </>}>
            <div className="form-grid">
                <Input fieldClass="span-6" label={t('rooms.fields.room_name')} required value={form.name} maxLength={30} onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldError(error, 'name')} />
                <Select fieldClass="span-6" label={t('rooms.fields.room_type')} value={form.room_type_id} options={roomTypes} onChange={(e) => setForm({ ...form, room_type_id: e.target.value })} error={fieldError(error, 'room_type_id')} />
                <Input fieldClass="span-6" label={t('rooms.fields.floor')} optional value={form.floor} maxLength={10} onChange={(e) => setForm({ ...form, floor: e.target.value })} />
                <Input fieldClass="span-6" label={t('rooms.fields.building')} optional value={form.building} maxLength={40} onChange={(e) => setForm({ ...form, building: e.target.value })} />
                <Textarea fieldClass="span-12" label={t('rooms.fields.notes')} optional rows={3} maxLength={500} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
                <div className="field span-12">
                    <span className="field-label">{t('rooms.fields.status')}</span>
                    <Toggle checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} label={t(`ui.status.${form.is_active ? 'active' : 'inactive'}`)} />
                    {fieldError(error, 'is_active') && <div className="field-error">{fieldError(error, 'is_active')}</div>}
                </div>
            </div>
        </Modal>
    );
}

/** Out of order / maintenance / owner hold for a date range (end = first night back in service). */
/** The day after a Y-m-d date. Plain calendar arithmetic in UTC, so the browser time zone cannot shift it. */
function nextDay(date: string): string {
    return new Date(Date.parse(`${date}T00:00:00Z`) + 86400000).toISOString().slice(0, 10);
}

export function BlockRoomModal({ room, blockTypes, today, onClose, onSaved }: { room: RoomDetail; blockTypes: Option[]; today: string; onClose: () => void; onSaved: () => void }) {
    const tomorrow = nextDay(today);
    const [form, setForm] = useState({ block_type: 'maintenance', start_date: today, end_date: tomorrow, reason: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`/rooms/${room.id}/blocks`), { ...form, reason: form.reason || null }), setError);
        setBusy(false);
        if (res) onSaved();
    };

    return (
        <Modal open title={`${t('rooms.mark_out_of_service')} · ${room.name}`} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="danger" icon="ban" loading={busy} onClick={save}>{t('rooms.block_room')}</Button>
        </>}>
            <div className="form-grid">
                <Select fieldClass="span-12" label={t('rooms.block_fields.type')} value={form.block_type} options={blockTypes} onChange={(e) => setForm({ ...form, block_type: e.target.value })} error={fieldError(error, 'block_type')} />
                <Input fieldClass="span-6" type="date" label={t('rooms.block_fields.start_date')} required min={today} value={form.start_date} onChange={(e) => { const start = e.target.value; setForm({ ...form, start_date: start, end_date: form.end_date > start ? form.end_date : nextDay(start) }); }} error={fieldError(error, 'start_date')} />
                <Input fieldClass="span-6" type="date" label={t('rooms.block_fields.end_date')} required min={form.start_date} value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} error={fieldError(error, 'end_date')} />
                <Input fieldClass="span-12" label={t('rooms.block_fields.reason')} optional maxLength={255} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
            </div>
        </Modal>
    );
}
