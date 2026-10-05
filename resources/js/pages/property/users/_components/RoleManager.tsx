import { useState } from 'react';
import { Alert, Badge, Button, Checkbox, ConfirmDialog, Drawer, Field, Icon, Input, Select, Textarea, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import type { Tone } from '@/lib/status';
import type { PermissionGroup, RoleInfo } from './types';

interface Draft { code: string | null; name: string; description: string; color: string; permissions: string[] }

const blank = (color: string): Draft => ({ code: null, name: '', description: '', color, permissions: [] });
const toDraft = (r: RoleInfo): Draft => ({ code: r.code, name: r.name, description: r.description ?? '', color: r.color, permissions: r.permissions });

/**
 * Role Management: system roles are shown read-only; custom roles of the
 * property can be created, edited and deleted.
 */
export function RoleManager({ roles, catalogue, colors, onClose }: { roles: RoleInfo[]; catalogue: PermissionGroup[]; colors: string[]; onClose: () => void }) {
    const [current, setCurrent] = useState<RoleInfo | null>(roles[0] ?? null);
    const [draft, setDraft] = useState<Draft>(roles[0] ? toDraft(roles[0]) : blank(colors[0]));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const readOnly = !!current?.is_system;
    const all = catalogue.flatMap((g) => g.permissions.map((p) => p.key));
    const allowAll = current?.permissions.length === all.length && readOnly;

    const pick = (r: RoleInfo | null) => {
        setCurrent(r);
        setDraft(r ? toDraft(r) : blank(colors[0]));
        setError(null);
    };
    const toggle = (key: string, on: boolean) => setDraft((d) => ({ ...d, permissions: on ? [...d.permissions, key] : d.permissions.filter((k) => k !== key) }));
    const toggleGroup = (g: PermissionGroup, on: boolean) => setDraft((d) => {
        const keys = g.permissions.map((p) => p.key);
        return { ...d, permissions: on ? Array.from(new Set([...d.permissions, ...keys])) : d.permissions.filter((k) => !keys.includes(k)) };
    });

    const save = async () => {
        setBusy(true);
        setError(null);
        const body = { name: draft.name, description: draft.description || null, color: draft.color, permissions: draft.permissions };
        try {
            const res = draft.code
                ? await http.put<{ message: string }>(propertyApiUrl(`/roles/${draft.code}`), body)
                : await http.post<{ message: string }>(propertyApiUrl('/roles'), body);
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
        }
    };

    const remove = async () => {
        if (!draft.code) return;
        setBusy(true);
        try {
            const res = await http.delete<{ message: string }>(propertyApiUrl(`/roles/${draft.code}`));
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setBusy(false);
            setConfirmDelete(false);
        }
    };

    return (
        <Drawer open size="lg" title={t('roles.role_management')} onClose={onClose} footer={readOnly ? <Button onClick={onClose}>{t('ui.close')}</Button> : <>
            {draft.code && <Button variant="danger-soft" icon="trash" onClick={() => setConfirmDelete(true)} disabled={busy}>{t('ui.delete')}</Button>}
            <span className="grow" />
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save_changes')}</Button>
        </>}>
            <p className="muted">{t('roles.role_management_sub')}</p>
            <div style={{ display: 'grid', gridTemplateColumns: '230px minmax(0, 1fr)', gap: 20, alignItems: 'start' }}>
                <div className="role-list">
                    {roles.map((r) => (
                        <button key={`${r.code}-${r.is_system}`} type="button" className={`role-item${current === r ? ' active' : ''}`} onClick={() => pick(r)}>
                            <span className="grow">
                                <Badge tone={r.color as Tone}>{r.name}</Badge>
                                <span className="text-xs muted" style={{ display: 'block', marginTop: 4 }}>
                                    {r.is_system ? t('roles.system') : t('roles.custom')} · {t('roles.users_count', { n: r.users ?? 0 })}
                                </span>
                            </span>
                            {r.is_system && <Icon name="lock" size={14} />}
                        </button>
                    ))}
                    <Button variant="outline" icon="plus" onClick={() => pick(null)}>{t('roles.add_role')}</Button>
                </div>

                <div className="stack">
                    {readOnly && <Alert tone="info">{t('roles.system_read_only')}</Alert>}
                    {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
                    <div className="form-grid">
                        <Input fieldClass="span-8" label={t('roles.name')} required value={draft.name} disabled={readOnly} onChange={(e) => setDraft({ ...draft, name: e.target.value })} error={error?.field('name')} />
                        <Select fieldClass="span-4" label={t('roles.color')} value={draft.color} disabled={readOnly} options={colors.map((c) => ({ value: c, label: t(`roles.colors.${c}`) }))}
                            onChange={(e) => setDraft({ ...draft, color: e.target.value })} error={error?.field('color')} />
                        <Textarea fieldClass="span-12" label={t('roles.description')} optional rows={2} value={draft.description} disabled={readOnly}
                            onChange={(e) => setDraft({ ...draft, description: e.target.value })} error={error?.field('description')} />
                    </div>
                    <Field label={t('roles.permissions')} error={error?.field('permissions')}>
                        {allowAll && <Alert tone="success">{t('roles.full_access')}</Alert>}
                        <div className="perm-grid">
                            {catalogue.map((g) => {
                                const keys = g.permissions.map((p) => p.key);
                                const allOn = keys.every((k) => draft.permissions.includes(k));
                                return (
                                    <div key={g.module} className="perm-group">
                                        <h4><Checkbox label={g.label} checked={allOn} disabled={readOnly} onChange={(e) => toggleGroup(g, e.target.checked)} /></h4>
                                        <div className="stack" style={{ gap: 2 }}>
                                            {g.permissions.map((p) => (
                                                <Checkbox key={p.key} label={p.label} checked={draft.permissions.includes(p.key)} disabled={readOnly} onChange={(e) => toggle(p.key, e.target.checked)} />
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </Field>
                </div>
            </div>
            <ConfirmDialog open={confirmDelete} danger busy={busy} title={t('ui.delete')} message={t('roles.delete_confirm', { name: draft.name })}
                confirmLabel={t('ui.delete')} onConfirm={remove} onClose={() => setConfirmDelete(false)} />
        </Drawer>
    );
}
