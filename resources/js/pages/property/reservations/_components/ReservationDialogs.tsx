import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Checkbox, EmptyState, Input, Modal, Select, Textarea, toast } from '@/components/ui';
import { date, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import type { ReservationDetail, ReservationRoomDetail } from './types';

export type DialogKind = 'cancel' | 'no_show' | 'check_in' | 'check_out' | 'assign' | 'confirm';

interface Props {
    reservation: ReservationDetail;
    kind: DialogKind | null;
    roomId?: string | null;
    idTypes?: { value: string; label: string }[];
    onClose: () => void;
    onDone: (r: ReservationDetail) => void;
    onAssign?: (roomId: string) => void;
}

/**
 * Every reservation action dialog in one place (detail page, side panel, front desk):
 * busy state on the button, inline field errors from the server, toast with the error
 * reference on failure, Esc closes, Enter submits.
 */
export function ReservationDialogs({ reservation: r, kind, roomId, idTypes, onClose, onDone, onAssign }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<Record<string, string | boolean>>({});
    const set = (k: string, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }));

    useEffect(() => {
        setError(null);
        setForm(kind === 'check_in' ? { id_type: r.guest_profile?.id_type ?? '', id_number: '' } : {});
    }, [kind, r.guest_profile?.id_type]);

    if (!kind) return null;

    const run = async (path: string, body: Record<string, unknown>) => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string; reservation: ReservationDetail }>(propertyApiUrl(`/reservations/${r.id}/${path}`), body);
            toast.success(res.message);
            onClose();
            onDone(res.reservation);
        } catch (e) {
            const err = e as ApiError;
            setError(err);
            const fieldMessage = Object.values(err.fields ?? {})[0]?.[0];
            toast.error(fieldMessage ?? err.message, err.status >= 500 ? err.ref : undefined);
        } finally {
            setBusy(false);
        }
    };
    const fieldErr = (k: string) => error?.field(k);
    const firstError = error ? Object.values(error.fields ?? {})[0]?.[0] : undefined;
    const cur = r.currency;
    const footer = (label: string, onClick: () => void, danger = false) => <>
        <Button onClick={onClose} disabled={busy}>{t('ui.cancel')}</Button>
        <Button variant={danger ? 'danger' : 'primary'} loading={busy} onClick={onClick}>{label}</Button>
    </>;
    const submitOnEnter = (fn: () => void) => (e: React.FormEvent) => { e.preventDefault(); if (!busy) fn(); };

    if (kind === 'cancel') {
        const fee = r.cancellation.fee_now;
        const go = () => run('cancel', { reason: form.reason || null, waive_fee: !!form.waive_fee });
        return (
            <Modal open title={t('reservations.dialogs.cancel_title', { ref: r.ref })} onClose={onClose} footer={footer(t('reservations.dialogs.cancel_confirm'), go, true)}>
                <form className="stack" onSubmit={submitOnEnter(go)}>
                    <p>{t('reservations.dialogs.cancel_text')}</p>
                    {fee !== null && Number(fee) > 0
                        ? <Alert tone="warn">{t('reservations.dialogs.cancel_fee', { fee: money(fee, cur) })}</Alert>
                        : <Alert tone="info">{t('reservations.dialogs.cancel_free')}</Alert>}
                    <Textarea label={t('reservations.fields.reason')} optional rows={2} maxLength={255} autoFocus value={String(form.reason ?? '')} onChange={(e) => set('reason', e.target.value)} error={fieldErr('reason')} />
                    {fee !== null && Number(fee) > 0 && <Checkbox checked={!!form.waive_fee} onChange={(e) => set('waive_fee', e.target.checked)} label={t('reservations.dialogs.waive_fee')} />}
                    {firstError && !fieldErr('reason') && <Alert tone="danger">{firstError}</Alert>}
                </form>
            </Modal>
        );
    }

    if (kind === 'no_show' || kind === 'confirm') {
        const go = () => run(kind === 'no_show' ? 'no-show' : 'confirm', {});
        return (
            <Modal open title={t(kind === 'no_show' ? 'reservations.dialogs.no_show_title' : 'reservations.dialogs.confirm_title', { ref: r.ref })} onClose={onClose}
                footer={footer(t(kind === 'no_show' ? 'reservations.dialogs.no_show_confirm' : 'reservations.actions.confirm'), go, kind === 'no_show')}>
                <p>{kind === 'no_show' ? t('reservations.dialogs.no_show_text', { fee: money(r.cancellation.fee_now ?? '0', cur) }) : t('reservations.dialogs.confirm_text')}</p>
                {firstError && <Alert tone="danger">{firstError}</Alert>}
            </Modal>
        );
    }

    if (kind === 'check_in') {
        const unassigned = r.rooms.filter((x) => ['pending', 'confirmed'].includes(x.status) && !x.unit);
        const go = () => run('check-in', {
            note: form.note || null,
            guest: { id_type: form.id_type || null, id_number: form.id_number || null },
        });
        return (
            <Modal open title={t('reservations.dialogs.check_in_title', { guest: r.guest.name })} onClose={onClose} footer={footer(t('reservations.dialogs.check_in_confirm'), go)}>
                <form className="stack" onSubmit={submitOnEnter(go)}>
                    <p className="muted">{t('reservations.dialogs.check_in_text')}</p>
                    {unassigned.length > 0 && <Alert tone="warn">
                        {t('reservations.errors.assign_first')}{' '}
                        {onAssign && <button type="button" className="link-button" onClick={() => onAssign(unassigned[0].id)}>{t('reservations.actions.assign_room')}</button>}
                    </Alert>}
                    <div className="form-grid">
                        <Select fieldClass="span-6" label={t('guests.fields.id_type')} value={String(form.id_type ?? '')} placeholder="—" options={idTypes ?? []}
                            onChange={(e) => set('id_type', e.target.value)} error={fieldErr('guest.id_type')} />
                        <Input fieldClass="span-6" label={t('guests.fields.id_number')} optional value={String(form.id_number ?? '')} maxLength={40} autoFocus
                            placeholder={r.guest_profile?.id_number ?? ''} onChange={(e) => set('id_number', e.target.value)} error={fieldErr('guest.id_number')} />
                        <Input fieldClass="span-12" label={t('reservations.fields.note')} optional value={String(form.note ?? '')} maxLength={255} onChange={(e) => set('note', e.target.value)} />
                    </div>
                    {firstError && !fieldErr('guest.id_number') && <Alert tone="danger">{firstError}</Alert>}
                    <button type="submit" hidden />
                </form>
            </Modal>
        );
    }

    if (kind === 'check_out') {
        const balance = Number(r.totals.balance);
        const early = r.check_out > r.today;
        const go = () => run('check-out', { note: form.note || null, override_balance: !!form.override });
        return (
            <Modal open title={t('reservations.dialogs.check_out_title', { guest: r.guest.name })} onClose={onClose} footer={footer(t('reservations.dialogs.check_out_confirm'), go)}>
                <form className="stack" onSubmit={submitOnEnter(go)}>
                    <p className="muted">{t('reservations.dialogs.check_out_text')}</p>
                    {balance > 0 && <Alert tone="warn">{t('reservations.dialogs.check_out_balance', { amount: money(r.totals.balance, cur) })}</Alert>}
                    {early && <Alert tone="info">{t('reservations.dialogs.check_out_early')}</Alert>}
                    {balance > 0 && r.actions.override_balance && <Checkbox checked={!!form.override} onChange={(e) => set('override', e.target.checked)} label={t('reservations.dialogs.check_out_override')} />}
                    <Input label={t('reservations.fields.note')} optional value={String(form.note ?? '')} maxLength={255} autoFocus onChange={(e) => set('note', e.target.value)} />
                    {firstError && <Alert tone="danger">{firstError}</Alert>}
                </form>
            </Modal>
        );
    }

    return <AssignDialog r={r} roomId={roomId ?? null} onClose={onClose} onDone={onDone} />;
}

/** Pick a free PMS room of the room type for the stay (or tonight on for an in-house guest). */
function AssignDialog({ r, roomId, onClose, onDone }: { r: ReservationDetail; roomId: string | null; onClose: () => void; onDone: (r: ReservationDetail) => void }) {
    const open = r.rooms.filter((x) => !['cancelled', 'no_show', 'checked_out'].includes(x.status));
    const [selectedRoom, setSelectedRoom] = useState<string>(roomId ?? open.find((x) => !x.unit)?.id ?? open[0]?.id ?? '');
    const room: ReservationRoomDetail | undefined = open.find((x) => x.id === selectedRoom);
    const [units, setUnits] = useState<{ id: string; name: string; floor: string | null; housekeeping: string }[] | null>(null);
    const [unit, setUnit] = useState('');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);
    const seq = useRef(0);

    useEffect(() => {
        if (!room?.room_type) return;
        const n = ++seq.current;
        setUnits(null);
        setFailed(null);
        http.get<{ units: { id: string; name: string; floor: string | null; housekeeping: string }[] }>(propertyApiUrl('/reservations/units'), {
            room_type_id: room.room_type.id, check_in: room.check_in, check_out: room.check_out, room_id: room.id,
        }).then((res) => {
            if (n !== seq.current) return;
            setUnits(res.units);
            setUnit(room.unit?.id ?? res.units.find((u) => u.housekeeping !== 'dirty')?.id ?? res.units[0]?.id ?? '');
        }).catch((e: ApiError) => n === seq.current && setFailed(e.message));
    }, [room?.id]);

    const save = async (unitId: string | null) => {
        if (!room) return;
        setBusy(true);
        try {
            const res = await http.post<{ message: string; reservation: ReservationDetail }>(propertyApiUrl(`/reservations/${r.id}/assign`), { room_id: room.id, unit_id: unitId });
            toast.success(res.message);
            onClose();
            onDone(res.reservation);
        } catch (e) {
            const err = e as ApiError;
            toast.error(err.field('unit_id') ?? err.message, err.status >= 500 ? err.ref : undefined);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal open title={t('reservations.dialogs.assign_title', { room_type: room?.room_type?.name ?? '' })} onClose={onClose} footer={<>
            {room?.unit && room.status !== 'checked_in' && <Button variant="ghost" disabled={busy} onClick={() => save(null)}>{t('reservations.dialogs.remove_assignment')}</Button>}
            <span className="grow" />
            <Button onClick={onClose} disabled={busy}>{t('ui.cancel')}</Button>
            <Button variant="primary" loading={busy} disabled={!unit} onClick={() => save(unit)}>{t('reservations.dialogs.assign_confirm')}</Button>
        </>}>
            <form className="stack" onSubmit={(e) => { e.preventDefault(); if (unit && !busy) save(unit); }}>
                {open.length > 1 && <Select label={t('reservations.fields.room')} value={selectedRoom} onChange={(e) => setSelectedRoom(e.target.value)}
                    options={open.map((x, i) => ({ value: x.id, label: `${t('reservations.detail.room_n', { n: i + 1 })} · ${x.room_type?.name ?? ''}${x.unit ? ` · ${x.unit.name}` : ''}` }))} />}
                {room && <p className="muted">{room.status === 'checked_in' ? t('reservations.dialogs.assign_move_text') : t('reservations.dialogs.assign_text', { from: date(room.check_in), to: date(room.check_out) })}</p>}
                {failed && <Alert tone="danger">{failed}</Alert>}
                {!failed && units === null && <div className="skeleton" style={{ height: 40 }} />}
                {units !== null && units.length === 0 && <EmptyState icon="door-closed" title={t('reservations.dialogs.assign_none')} />}
                {units !== null && units.length > 0 && <div className="unit-picker" role="radiogroup">
                    {units.map((u) => (
                        <label key={u.id} className={`unit-option${unit === u.id ? ' active' : ''}`} title={t(`ui.status.${u.housekeeping}`)}>
                            <input type="radio" name="unit" value={u.id} checked={unit === u.id} onChange={() => setUnit(u.id)} />
                            <span className="strong">{u.name}</span>
                            <span className={`hk-dot tone-${u.housekeeping === 'dirty' ? 'amber' : 'green'}`} />
                            <span className="text-xs muted">{t(`ui.status.${u.housekeeping}`)}</span>
                        </label>
                    ))}
                </div>}
            </form>
        </Modal>
    );
}
