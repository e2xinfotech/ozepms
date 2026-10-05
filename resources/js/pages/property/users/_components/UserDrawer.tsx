import { useState } from 'react';
import { Alert, Button, Drawer, FormSection, Input, Select, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import type { RoleInfo, UserDetail } from './types';

/** Add a user to the property, or edit one (name, title, phone, role). */
export function UserDrawer({ user, roles, locales, onClose, onSaved }: {
    user: UserDetail | null;
    roles: RoleInfo[];
    locales: Record<string, string>;
    onClose: () => void;
    onSaved: (id: string) => void;
}) {
    const editing = !!user;
    const assignable = roles.filter((r) => r.assignable);
    const [data, setData] = useState({
        name: user?.name ?? '',
        email: user?.email ?? '',
        job_title: user?.job_title ?? '',
        phone_e164: user?.phone_e164 ?? '',
        locale: user?.locale ?? 'en',
        role: user?.role ?? assignable[0]?.code ?? '',
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const set = (key: keyof typeof data, value: string) => setData((d) => ({ ...d, [key]: value }));
    const role = roles.find((r) => r.code === data.role);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const body: Record<string, unknown> = { name: data.name, job_title: data.job_title || null, phone_e164: data.phone_e164 || null };
            if (!user?.is_owner) body.role = data.role;
            const res = editing
                ? await http.put<{ message: string; user: UserDetail }>(propertyApiUrl(`/users/${user!.id}`), body)
                : await http.post<{ message: string; user: UserDetail }>(propertyApiUrl('/users'), { ...body, email: data.email, locale: data.locale });
            toast.success(res.message);
            onSaved(res.user.id);
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
        }
    };

    return (
        <Drawer open title={editing ? t('users.edit_user') : t('users.add_user')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon={editing ? 'save' : 'user-plus'} loading={busy} onClick={save}>{editing ? t('ui.save_changes') : t('users.add_user')}</Button>
        </>}>
            {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
            {!editing && <Alert tone="info">{t('users.invite_hint')}</Alert>}
            <FormSection title={t('users.section_details')}>
                <Input fieldClass="span-12" label={t('users.name')} required value={data.name} onChange={(e) => set('name', e.target.value)} error={error?.field('name')} />
                <Input fieldClass="span-12" label={t('users.email')} required type="email" icon="mail" value={data.email} disabled={editing}
                    onChange={(e) => set('email', e.target.value)} error={error?.field('email')} />
                <Input fieldClass="span-6" label={t('users.job_title')} optional value={data.job_title} onChange={(e) => set('job_title', e.target.value)} error={error?.field('job_title')} />
                <Input fieldClass="span-6" label={t('users.phone')} optional icon="phone" placeholder="+971501234567" value={data.phone_e164} onChange={(e) => set('phone_e164', e.target.value)} error={error?.field('phone_e164')} hint={t('users.phone_hint')} />
                {!editing && (
                    <Select fieldClass="span-6" label={t('users.language')} value={data.locale} options={Object.entries(locales).map(([value, label]) => ({ value, label }))}
                        onChange={(e) => set('locale', e.target.value)} error={error?.field('locale')} />
                )}
            </FormSection>
            <FormSection title={t('users.role')}>
                {user?.is_owner ? (
                    <div className="span-12"><Alert tone="info">{t('users.owner_role_fixed')}</Alert></div>
                ) : (
                    <Select fieldClass="span-12" label={t('users.role')} required value={data.role} options={assignable.map((r) => ({ value: r.code, label: r.name }))}
                        onChange={(e) => set('role', e.target.value)} error={error?.field('role')} hint={role?.description ?? undefined} />
                )}
            </FormSection>
        </Drawer>
    );
}
