import { useEffect, useState } from 'react';
import { Badge, Button, ConfirmDialog, EmptyState, Icon, KeyValue, SidePanel, Tabs, toast } from '@/components/ui';
import { ActivityList, PermissionView, RoleBadge, UserHeader } from '@/components/users/UserBits';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import type { PermissionGroup, UserDetail } from './types';

type Pending = 'disable' | 'enable' | 'remove' | null;

/** Right-hand panel of Users & Roles for the selected user. */
export function UserPanel({ id, me, catalogue, onClose, onEdit, onDuplicate }: {
    id: string; me: string; catalogue: PermissionGroup[]; onClose: () => void; onEdit: (u: UserDetail) => void; onDuplicate?: (u: UserDetail) => void;
}) {
    const [user, setUser] = useState<UserDetail | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [tab, setTab] = useState('overview');
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        setUser(null);
        setFailed(null);
        http.get<{ user: UserDetail }>(propertyApiUrl(`/users/${id}`))
            .then((res) => alive && setUser(res.user))
            .catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id]);

    if (failed) return <SidePanel title={t('users.user')} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!user) return <SidePanel title={t('ui.loading')} onClose={onClose}><div className="sp-section stack"><div className="skeleton" style={{ height: 72 }} /><div className="skeleton" style={{ height: 200 }} /></div></SidePanel>;

    const self = user.id === me;
    const run = async (action: () => Promise<{ message: string }>, reload = true) => {
        setBusy(true);
        try {
            const res = await action();
            toast.success(res.message);
            if (reload) window.location.reload(); else setBusy(false);
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setBusy(false);
        }
        setPending(null);
    };
    const confirmAction = () => {
        if (pending === 'remove') return run(() => http.delete(propertyApiUrl(`/users/${user.id}`)));
        if (pending === 'disable' || pending === 'enable') {
            return run(() => http.put(propertyApiUrl(`/users/${user.id}`), { status: pending === 'disable' ? 'disabled' : 'active' }));
        }
    };

    return (
        <SidePanel title={user.name} onClose={onClose}>
            <UserHeader name={user.name} avatar={user.avatar} status={user.status} roleName={user.role_name} roleDescription={user.role_description}
                action={<Button size="sm" variant="outline" icon="pencil" onClick={() => onEdit(user)}>{t('users.edit_user')}</Button>} />
            <div style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={[
                    { key: 'overview', label: t('users.tabs.overview') },
                    { key: 'permissions', label: t('users.tabs.permissions') },
                    { key: 'properties', label: t('users.tabs.properties') },
                    { key: 'activity', label: t('users.tabs.activity') },
                ]} />
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'overview' && (
                    <KeyValue items={[
                        { icon: 'user', label: t('users.name'), value: user.name },
                        { icon: 'mail', label: t('users.email'), value: user.email },
                        { icon: 'phone', label: t('users.phone'), value: user.phone_e164 },
                        { icon: 'briefcase', label: t('users.job_title'), value: user.job_title },
                        { icon: 'user-cog', label: t('users.role'), value: <span className="row" style={{ gap: 8 }}><RoleBadge name={user.role_name} color={user.role_color} />{user.is_owner && user.role !== 'owner' && <Badge size="sm" tone="violet">{t('users.owner')}</Badge>}</span> },
                        { icon: 'activity', label: t('users.status'), value: <Badge status={user.status} /> },
                        { icon: 'log-in', label: t('users.last_login'), value: user.last_login_at ? dateTime(user.last_login_at) : t('ui.never') },
                        { icon: 'calendar-plus', label: t('users.created_on'), value: dateTime(user.created_at) },
                        { icon: 'shield-check', label: t('users.two_factor'), value: <Badge status={user.two_factor ? 'enabled' : 'disabled'} /> },
                    ]} />
                )}
                {tab === 'permissions' && <PermissionView catalogue={catalogue} granted={user.permissions} />}
                {tab === 'properties' && (
                    <ul className="list-plain">
                        {user.properties.map((p) => (
                            <li key={p.code} className="activity">
                                <span className="a-icon tone-blue"><Icon name="hotel" size={16} /></span>
                                <div className="grow"><div className="a-title">{p.name}</div><div className="a-sub">{p.code}{p.city && ` · ${p.city}`} · {p.role_name}</div></div>
                                <Badge size="sm" status={p.status} />
                            </li>
                        ))}
                    </ul>
                )}
                {tab === 'activity' && <ActivityList items={user.activity} />}
            </div>
            <div className="sp-section">
                <h3>{t('ui.quick_actions')}</h3>
                <div className="action-grid">
                    <Button variant="outline" icon="key" disabled={busy} onClick={() => run(() => http.post(propertyApiUrl(`/users/${user.id}/password-link`)), false)}>{t('users.reset_password')}</Button>
                    {user.status === 'disabled'
                        ? <Button variant="outline" icon="check-circle" disabled={busy || self} onClick={() => setPending('enable')}>{t('users.enable_user')}</Button>
                        : <Button variant="danger-soft" icon="pause" disabled={busy || self || user.is_owner} title={user.is_owner ? t('users.owner_cannot_disable') : undefined} onClick={() => setPending('disable')}>{t('users.disable_user')}</Button>}
                    {onDuplicate && <Button variant="outline" icon="copy" disabled={busy} title={t('users.duplicate_hint')} onClick={() => onDuplicate(user)}>{t('users.duplicate_user')}</Button>}
                    <Button variant="danger-soft" icon="trash" disabled={busy || self || user.is_owner} title={user.is_owner ? t('users.owner_cannot_remove') : undefined} onClick={() => setPending('remove')}>{t('users.remove_user')}</Button>
                </div>
            </div>
            <ConfirmDialog open={pending !== null} danger={pending !== 'enable'} busy={busy}
                title={pending === 'remove' ? t('users.remove_user') : pending === 'enable' ? t('users.enable_user') : t('users.disable_user')}
                message={pending === 'remove' ? t('users.remove_confirm', { name: user.name }) : pending === 'enable' ? t('users.enable_confirm', { name: user.name }) : t('users.disable_confirm', { name: user.name })}
                onConfirm={confirmAction} onClose={() => setPending(null)} />
        </SidePanel>
    );
}
