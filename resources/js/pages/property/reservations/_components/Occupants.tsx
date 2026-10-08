import { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Button, Icon, Input, Select, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

type PersonType = 'adult' | 'child' | 'infant';
interface Doc { id: string; type: string; name: string }
export interface Person {
    id: string | null; person_type: PersonType; age: string; first_name: string; last_name: string; date_of_birth: string; gender: string;
    nationality_iso2: string; id_type: string; id_number: string; documents: Doc[]; file: File | null;
}
interface RoomState { room_id: string; index: number; room_type: string | null; adults: number; children: number; infants: number; people: Person[] }
interface Loaded { rooms: RoomState[]; bands: { infant: { min: number; max: number }; child: { min: number; max: number } }; countries: Option[] }

const blank = (type: PersonType): Person => ({ id: null, person_type: type, age: '', first_name: '', last_name: '', date_of_birth: '', gender: '', nationality_iso2: '', id_type: '', id_number: '', documents: [], file: null });

/** Rows to show: the saved people first, then empty rows up to the head count of the booking (the main guest is the first adult of the first room). */
function withSlots(rooms: RoomState[]): RoomState[] {
    return rooms.map((room, i) => {
        const saved: Person[] = room.people.map((p) => ({ ...p, age: p.age === null || p.age === undefined ? '' : String(p.age), date_of_birth: p.date_of_birth ?? '', gender: p.gender ?? '', nationality_iso2: p.nationality_iso2 ?? '', id_type: p.id_type ?? '', id_number: p.id_number ?? '', last_name: p.last_name ?? '', file: null as File | null }));
        const want: Record<PersonType, number> = { adult: Math.max(0, room.adults - (i === 0 ? 1 : 0)), child: room.children, infant: room.infants };
        const rows = [...saved];
        (['adult', 'child', 'infant'] as PersonType[]).forEach((type) => {
            for (let n = rows.filter((p) => p.person_type === type).length; n < want[type]; n++) rows.push(blank(type));
        });
        return { ...room, people: rows };
    });
}

/** Loads and saves everyone staying besides the main guest. All details optional. */
export function useOccupants(reservationId: string, enabled: boolean) {
    const [data, setData] = useState<Loaded | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const loading = useRef(false);

    const load = useCallback(async () => {
        if (loading.current) return;
        loading.current = true;
        try {
            const res = await http.get<Loaded>(propertyApiUrl(`/reservations/${reservationId}/occupants`));
            setData({ ...res, rooms: withSlots(res.rooms) });
        } catch (e) {
            setError(e as ApiError);
        } finally {
            loading.current = false;
        }
    }, [reservationId]);

    useEffect(() => { if (enabled) void load(); }, [enabled, load]);

    const setPerson = (roomId: string, idx: number, patch: Partial<Person>) =>
        setData((d) => d && { ...d, rooms: d.rooms.map((r) => (r.room_id === roomId ? { ...r, people: r.people.map((p, i) => (i === idx ? { ...p, ...patch } : p)) } : r)) });

    /** Saves every room, then uploads the chosen ID files. Throws the ApiError of the first failure. */
    const save = async (): Promise<void> => {
        if (!data) return;
        setError(null);
        for (const room of data.rooms) {
            const rows = room.people;
            let res: { people: { id: string }[] };
            try {
                res = await http.put<{ people: { id: string }[] }>(propertyApiUrl(`/reservations/${reservationId}/occupants`), {
                    room_id: room.room_id,
                    occupants: rows.map((p) => ({
                        id: p.id, person_type: p.person_type, age: p.age === '' ? null : Number(p.age), first_name: p.first_name, last_name: p.last_name,
                        date_of_birth: p.date_of_birth || null, gender: p.gender || null, nationality_iso2: p.nationality_iso2 || null,
                        id_type: p.id_type || null, id_number: p.id_number || null,
                    })),
                });
            } catch (e) {
                setError(e as ApiError);
                throw e;
            }
            // Saved people come back in order of the non-empty rows; rows with data and a file get the upload.
            const filled = rows.filter((p) => p.first_name.trim() !== '' || p.last_name.trim() !== '' || p.id_number !== '' || p.age !== '' || p.date_of_birth !== '' || p.nationality_iso2 !== '');
            for (let i = 0; i < filled.length; i++) {
                const file = filled[i].file;
                const gid = res.people[i]?.id;
                if (file && gid) {
                    const form = new FormData();
                    form.append('file', file);
                    form.append('type', 'id_front');
                    await http.post(propertyApiUrl(`/guests/${gid}/documents`), form);
                }
            }
        }
        setData(null);
        loading.current = false;
        await load();
    };

    return { data, error, setPerson, save, reload: load };
}

export function OccupantsForm({ state, readOnly }: { state: ReturnType<typeof useOccupants>; readOnly?: boolean }) {
    const { data, error, setPerson } = state;
    if (error && !data) return <Alert tone="danger">{error.message}</Alert>;
    if (!data) return <div className="skeleton" style={{ height: 80 }} />;
    const total = data.rooms.reduce((n, r) => n + r.people.length, 0);
    if (total === 0) return <p className="muted">{t('reservations.occupants.none')}</p>;
    const idTypes = ['passport', 'national_id', 'driving_licence', 'voter_id', 'other'].map((v) => ({ value: v, label: t(`guests.id_types.${v}`) }));
    const ageHint = (type: PersonType) => type === 'infant' ? `${data.bands.infant.min}–${data.bands.infant.max}` : type === 'child' ? `${data.bands.child.min}–${data.bands.child.max}` : `${data.bands.child.max + 1}+`;

    return (
        <div className="stack occupants">
            <p className="muted text-sm">{t('reservations.occupants.hint')}</p>
            {data.rooms.map((room, ri) => room.people.length > 0 && (
                <div key={room.room_id} className="occupant-room">
                    {data.rooms.length > 1 && <h4>{t('reservations.detail.room_n', { n: room.index })} · {room.room_type}</h4>}
                    {ri === 0 && <p className="muted text-sm"><Icon name="user" size={14} /> {t('reservations.occupants.main_guest_first')}</p>}
                    {room.people.map((p, i) => (
                        <fieldset key={p.id ?? `${room.room_id}-${i}`} className="occupant">
                            <legend>{t(`reservations.occupants.type_${p.person_type}`)} {room.people.filter((x, k) => k <= i && x.person_type === p.person_type).length + (p.person_type === 'adult' && ri === 0 ? 1 : 0)}</legend>
                            <div className="form-grid">
                                <Input fieldClass="span-4" label={t('guests.fields.first_name')} optional maxLength={80} disabled={readOnly} value={p.first_name} onChange={(e) => setPerson(room.room_id, i, { first_name: e.target.value })} error={error?.field(`occupants.${i}.first_name`)} />
                                <Input fieldClass="span-4" label={t('guests.fields.last_name')} optional maxLength={80} disabled={readOnly} value={p.last_name} onChange={(e) => setPerson(room.room_id, i, { last_name: e.target.value })} />
                                <Input fieldClass="span-2" type="number" min={0} max={120} label={`${t('reservations.occupants.age')} (${ageHint(p.person_type)})`} optional disabled={readOnly} value={p.age} onChange={(e) => setPerson(room.room_id, i, { age: e.target.value })} error={error?.field(`occupants.${i}.age`)} />
                                <Select fieldClass="span-2" label={t('reservations.occupants.gender')} optional disabled={readOnly} value={p.gender} placeholder="—" options={['female', 'male', 'other'].map((v) => ({ value: v, label: t(`reservations.occupants.gender_${v}`) }))} onChange={(e) => setPerson(room.room_id, i, { gender: e.target.value })} />
                                <Input fieldClass="span-3" type="date" label={t('guests.fields.date_of_birth')} optional disabled={readOnly} value={p.date_of_birth} onChange={(e) => setPerson(room.room_id, i, { date_of_birth: e.target.value })} />
                                <Select fieldClass="span-3" label={t('guests.fields.nationality_iso2')} optional disabled={readOnly} value={p.nationality_iso2} placeholder="—" options={data.countries} onChange={(e) => setPerson(room.room_id, i, { nationality_iso2: e.target.value })} />
                                <Select fieldClass="span-3" label={t('guests.fields.id_type')} optional disabled={readOnly} value={p.id_type} placeholder="—" options={idTypes} onChange={(e) => setPerson(room.room_id, i, { id_type: e.target.value })} />
                                <Input fieldClass="span-3" label={t('guests.fields.id_number')} optional maxLength={40} disabled={readOnly} value={p.id_number} onChange={(e) => setPerson(room.room_id, i, { id_number: e.target.value })} />
                                {!readOnly && <div className="field span-12">
                                    <span className="field-label">{t('reservations.occupants.id_file')} <span className="opt">({t('ui.optional')})</span></span>
                                    <div className="row" style={{ gap: 8, flexWrap: 'wrap' }}>
                                        <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" aria-label={t('reservations.occupants.id_file')} onChange={(e) => setPerson(room.room_id, i, { file: e.target.files?.[0] ?? null })} />
                                        {p.documents.map((d) => <span key={d.id} className="badge tone-slate sm" title={d.name}><Icon name="file-text" size={12} /> {d.name}</span>)}
                                    </div>
                                </div>}
                            </div>
                        </fieldset>
                    ))}
                </div>
            ))}
        </div>
    );
}

/** Reservation page: edit the people staying and download the guest register (CSV). */
export function OccupantsCard({ reservationId, canRegister }: { reservationId: string; canRegister: boolean }) {
    const state = useOccupants(reservationId, true);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);
    const save = async () => {
        setBusy(true);
        setDone(false);
        try { await state.save(); setDone(true); } catch { /* shown in the form */ } finally { setBusy(false); }
    };
    return (
        <div className="stack">
            <OccupantsForm state={state} />
            <div className="row" style={{ justifyContent: 'flex-end', gap: 8 }}>
                {done && <span className="muted">{t('reservations.occupants.saved')}</span>}
                {canRegister && <a className="btn btn-outline" href={propertyApiUrl(`/reservations/${reservationId}/guest-register`)}><Icon name="download" size={16} /> {t('reservations.occupants.register')}</a>}
                <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save')}</Button>
            </div>
        </div>
    );
}
