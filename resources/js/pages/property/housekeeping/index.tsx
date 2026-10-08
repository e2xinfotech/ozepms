import { useMemo, useState } from 'react';
import { Alert, Badge, Button, ConfirmDialog, DataTable, EmptyState, Icon, Input, KpiCard, Modal, PageHeader, PillTabs, Select, Textarea, Toggle, type Column } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from '../_accommodation/shared';

interface Staff { id: string; name: string; email: string; phone: string | null; notes: string | null; is_active: boolean; rooms: number }
interface Room { id: string; name: string; floor: string | null; room_type: string | null; housekeeping_status: string; staff_id: string | null }
interface Task { id: string; room_id: string | null; room: string; status: 'pending' | 'done' | 'cancelled'; note: string | null; staff: string | null; created_at: string | null; notified_at: string | null; notified_to: string | null; completed_at: string | null }
interface Props {
    data: { staff: Staff[]; rooms: Room[]; tasks: Task[]; counts: { pending: number; unassigned: number; unassigned_rooms: number; staff: number } };
    filters: { tab?: string };
    can: { manage: boolean; update: boolean };
}

const TABS = ['tasks', 'rooms', 'staff'] as const;
type Tab = (typeof TABS)[number];

/** Housekeeping: who is responsible for cleaning which room, the staff list, and the cleaning tasks opened at check-out. */
function HousekeepingPage({ data, filters, can }: Props) {
    const tab: Tab = (TABS as readonly string[]).includes(filters.tab ?? '') ? (filters.tab as Tab) : 'tasks';
    const [staff, setStaff] = useState(data.staff);
    const [rooms, setRooms] = useState(data.rooms);
    const staffName = useMemo(() => Object.fromEntries(staff.map((s) => [s.id, s.name])), [staff]);

    return (
        <div className="content">
            <PageHeader title={t('housekeeping.title')} description={t('housekeeping.description')} actions={
                can.manage && <Button variant="primary" icon="user-plus" onClick={() => navigateWithQuery({ tab: 'staff', add: '1' })}>{t('housekeeping.add_staff')}</Button>
            } />
            <div className="kpi-row">
                <KpiCard icon="spray-can" tone="amber" label={t('housekeeping.kpi.pending')} value={data.counts.pending} />
                <KpiCard icon="user-round" tone={data.counts.unassigned ? 'red' : 'green'} label={t('housekeeping.kpi.unassigned')} value={data.counts.unassigned} />
                <KpiCard icon="door-open" tone={data.counts.unassigned_rooms ? 'orange' : 'green'} label={t('housekeeping.kpi.unassigned_rooms')} value={rooms.filter((r) => !r.staff_id).length} />
                <KpiCard icon="users" tone="blue" label={t('housekeeping.kpi.staff')} value={staff.filter((s) => s.is_active).length} />
            </div>
            <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'tasks' ? null : k, add: null })}
                items={TABS.map((k) => ({ key: k, label: t(`housekeeping.tabs.${k}`) }))} />
            {tab === 'tasks' && <TasksTab tasks={data.tasks} canUpdate={can.update || can.manage} />}
            {tab === 'rooms' && <RoomsTab rooms={rooms} staff={staff} staffName={staffName} canManage={can.manage}
                onAssigned={(ids, staffId) => {
                    setRooms((l) => l.map((r) => (ids.includes(r.id) ? { ...r, staff_id: staffId } : r)));
                    setStaff((l) => l.map((s) => ({ ...s, rooms: rooms.filter((r) => (ids.includes(r.id) ? staffId : r.staff_id) === s.id).length })));
                }} />}
            {tab === 'staff' && <StaffTab staff={staff} canManage={can.manage} />}
        </div>
    );
}

function TasksTab({ tasks, canUpdate }: { tasks: Task[]; canUpdate: boolean }) {
    const [busy, setBusy] = useState<string | null>(null);
    const markClean = async (task: Task) => {
        if (!task.room_id) return;
        setBusy(task.id);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`/rooms/${task.room_id}/housekeeping`), { housekeeping_status: 'clean' }));
        setBusy(null);
        if (res) window.location.reload();
    };
    const columns: Column<Task>[] = [
        { key: 'room', header: t('housekeeping.columns.room'), render: (r) => <span className="cell-main num">{r.room}</span> },
        { key: 'status', header: t('housekeeping.columns.status'), render: (r) => <Badge size="sm" tone={r.status === 'done' ? 'green' : r.status === 'pending' ? 'amber' : 'slate'}>{t(`housekeeping.task_status.${r.status}`)}</Badge> },
        { key: 'staff', header: t('housekeeping.columns.responsible'), render: (r) => r.staff ?? <span className="muted">{t('housekeeping.unassigned')}</span> },
        { key: 'opened', header: t('housekeeping.columns.opened'), render: (r) => <span className="num">{dateTime(r.created_at)}</span> },
        {
            key: 'mail', header: t('housekeeping.columns.email_sent'), render: (r) => r.notified_at
                ? <span className="row" title={t('housekeeping.sent_to', { email: r.notified_to ?? '' })}><Icon name="mail" size={16} /><span className="num">{dateTime(r.notified_at)}</span></span>
                : <span className="muted">{r.status === 'pending' && !r.staff ? t('housekeeping.waiting_for_person') : t('housekeeping.not_sent')}</span>,
        },
        {
            key: 'actions', header: t('housekeeping.columns.actions'), className: 'col-actions', render: (r) => canUpdate && r.status === 'pending' && r.room_id
                ? <Button size="sm" variant="outline" icon="check" loading={busy === r.id} onClick={() => markClean(r)}>{t('housekeeping.mark_clean')}</Button>
                : <span />,
        },
    ];
    return <DataTable columns={columns} rows={tasks} rowKey={(r) => r.id}
        empty={<EmptyState icon="spray-can" title={t('housekeeping.no_tasks')} text={t('housekeeping.no_tasks_hint')} />} />;
}

function RoomsTab({ rooms, staff, staffName, canManage, onAssigned }: {
    rooms: Room[]; staff: Staff[]; staffName: Record<string, string>; canManage: boolean; onAssigned: (ids: string[], staffId: string | null) => void;
}) {
    const [q, setQ] = useState('');
    const [person, setPerson] = useState('');
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const [target, setTarget] = useState('');
    const [busy, setBusy] = useState(false);

    const shown = rooms.filter((r) => (!q || `${r.name} ${r.room_type ?? ''}`.toLowerCase().includes(q.toLowerCase()))
        && (!person || (person === '-' ? !r.staff_id : r.staff_id === person)));
    const active = staff.filter((s) => s.is_active);

    const assign = async (ids: string[], staffId: string | null) => {
        setBusy(true);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl('/housekeeping/assign'), { rooms: ids, staff_id: staffId }));
        setBusy(false);
        if (res) { onAssigned(ids, staffId); setChecked(new Set()); }
    };

    const columns: Column<Room>[] = [
        { key: 'name', header: t('housekeeping.columns.room'), render: (r) => <span className="cell-main num">{r.name}</span> },
        { key: 'type', header: t('housekeeping.columns.room_type'), render: (r) => r.room_type ?? '—' },
        { key: 'floor', header: t('housekeeping.columns.floor'), align: 'center', render: (r) => r.floor ?? '—' },
        { key: 'hk', header: t('housekeeping.columns.housekeeping'), render: (r) => <Badge size="sm" status={r.housekeeping_status} /> },
        {
            key: 'staff', header: t('housekeeping.columns.responsible'), render: (r) => canManage
                ? <Select aria-label={t('housekeeping.fields.staff')} value={r.staff_id ?? ''} placeholder={t('housekeeping.unassigned')} disabled={busy}
                    options={active.concat(staff.filter((s) => !s.is_active && s.id === r.staff_id)).map((s) => ({ value: s.id, label: s.name }))}
                    onChange={(e) => assign([r.id], e.target.value || null)} />
                : (r.staff_id ? staffName[r.staff_id] : <span className="muted">{t('housekeeping.unassigned')}</span>),
        },
    ];

    return (
        <>
            <p className="muted text-sm">{t('housekeeping.rooms_hint')}</p>
            <div className="filter-bar">
                <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('housekeeping.search_rooms')} value={q} onChange={(e) => setQ(e.target.value)} />
                <Select label={t('housekeeping.columns.responsible')} value={person} placeholder={t('housekeeping.all_people')}
                    options={[{ value: '-', label: t('housekeeping.unassigned') }, ...staff.map((s) => ({ value: s.id, label: s.name }))]} onChange={(e) => setPerson(e.target.value)} />
                {canManage && checked.size > 0 && <>
                    <Select label={t('housekeeping.assign_selected')} value={target} placeholder={t('housekeeping.choose_person')}
                        options={[{ value: '-', label: t('housekeeping.nobody') }, ...active.map((s) => ({ value: s.id, label: s.name }))]} onChange={(e) => setTarget(e.target.value)} />
                    <Button variant="primary" icon="check" loading={busy} disabled={!target} onClick={() => assign([...checked], target === '-' ? null : target)}>
                        {t('housekeeping.assign')} · {t('housekeeping.selected_count', { count: checked.size })}
                    </Button>
                </>}
            </div>
            <DataTable columns={columns} rows={shown} rowKey={(r) => r.id} selectable={canManage} selected={checked} onSelect={setChecked}
                empty={<EmptyState icon="door-open" title={t('rooms.no_rooms')} />} />
        </>
    );
}

function StaffTab({ staff, canManage }: { staff: Staff[]; canManage: boolean }) {
    const [editing, setEditing] = useState<Staff | 'new' | null>(new URLSearchParams(window.location.search).get('add') ? 'new' : null);
    const [removing, setRemoving] = useState<Staff | null>(null);
    const [busy, setBusy] = useState(false);

    const remove = async () => {
        if (!removing) return;
        setBusy(true);
        const res = await act(() => http.delete<{ message: string }>(propertyApiUrl(`/housekeeping/staff/${removing.id}`)));
        setBusy(false);
        if (res) window.location.reload();
    };

    const columns: Column<Staff>[] = [
        { key: 'name', header: t('housekeeping.columns.name'), render: (r) => <span className="cell-main">{r.name}</span> },
        { key: 'email', header: t('housekeeping.columns.email'), render: (r) => <span className="row"><Icon name="mail" size={16} />{r.email}</span> },
        { key: 'phone', header: t('housekeeping.columns.phone'), render: (r) => r.phone ?? '—' },
        { key: 'rooms', header: t('housekeeping.columns.rooms'), align: 'right', render: (r) => <span className="num">{r.rooms}</span> },
        { key: 'status', header: t('housekeeping.columns.status'), render: (r) => <Badge size="sm" tone={r.is_active ? 'green' : 'slate'}>{t(`housekeeping.status_${r.is_active ? 'active' : 'inactive'}`)}</Badge> },
        {
            key: 'actions', header: t('housekeeping.columns.actions'), className: 'col-actions', render: (r) => canManage && (
                <span className="row" style={{ justifyContent: 'flex-end' }}>
                    <Button size="sm" variant="outline" icon="pencil" onClick={() => setEditing(r)}>{t('ui.edit')}</Button>
                    <Button size="sm" variant="ghost" icon="trash" title={t('housekeeping.delete_staff')} aria-label={t('housekeeping.delete_staff')} onClick={() => setRemoving(r)} />
                </span>
            ),
        },
    ];

    return (
        <>
            <p className="muted text-sm">{t('housekeeping.staff_hint')}</p>
            <DataTable columns={columns} rows={staff} rowKey={(r) => r.id}
                empty={<EmptyState icon="users" title={t('housekeeping.no_staff')} text={t('housekeeping.no_staff_hint')} />} />
            {editing && <StaffModal staff={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            <ConfirmDialog open={!!removing} danger busy={busy} title={t('housekeeping.delete_title', { name: removing?.name ?? '' })} message={t('housekeeping.delete_text')}
                confirmLabel={t('housekeeping.delete_staff')} onConfirm={remove} onClose={() => setRemoving(null)} />
        </>
    );
}

function StaffModal({ staff, onClose }: { staff: Staff | null; onClose: () => void }) {
    const [form, setForm] = useState({ name: staff?.name ?? '', email: staff?.email ?? '', phone: staff?.phone ?? '', notes: staff?.notes ?? '', is_active: staff?.is_active ?? true });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => staff
            ? http.put<{ message: string }>(propertyApiUrl(`/housekeeping/staff/${staff.id}`), form)
            : http.post<{ message: string }>(propertyApiUrl('/housekeeping/staff'), form), setError);
        setBusy(false);
        if (res) navigateWithQuery({ tab: 'staff', add: null }, true);
    };

    return (
        <Modal open title={staff ? t('housekeeping.edit_staff') : t('housekeeping.add_staff')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save')}</Button>
        </>}>
            <div className="form-grid">
                {error && Object.keys(error.fields).some((k) => !['name', 'email', 'phone', 'notes'].includes(k)) && <div className="span-12"><Alert tone="danger">{error.message}</Alert></div>}
                <Input fieldClass="span-12" label={t('housekeeping.fields.name')} required autoFocus maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldError(error, 'name')} />
                <Input fieldClass="span-6" label={t('housekeeping.fields.email')} required type="email" maxLength={190} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={fieldError(error, 'email')} />
                <Input fieldClass="span-6" label={t('housekeeping.fields.phone')} optional maxLength={40} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} error={fieldError(error, 'phone')} />
                <Textarea fieldClass="span-12" label={t('housekeeping.fields.notes')} optional maxLength={255} rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} error={fieldError(error, 'notes')} />
                {staff && <div className="field span-12">
                    <span className="field-label">{t('housekeeping.fields.status')}</span>
                    <Toggle checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} label={t(`housekeeping.status_${form.is_active ? 'active' : 'inactive'}`)} />
                </div>}
            </div>
        </Modal>
    );
}

createPage(HousekeepingPage);
