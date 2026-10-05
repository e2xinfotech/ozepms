import { useEffect, useState } from 'react';
import {
    Alert, Badge, Button, Checkbox, ConfirmDialog, DataTable, Drawer, EmptyState, Field, FormSection, Icon, Input, KeyValue, KpiCard,
    PageHeader, Pagination, PillTabs, RowMenu, Select, SidePanel, Tabs, toast, type Column, type Option, type PageMeta,
} from '@/components/ui';
import { ActivityList, PermissionView, RoleBadge, UserCell, UserHeader, type ActivityItem, type PermissionGroup } from '@/components/users/UserBits';
import { createPage } from '@/lib/boot';
import { dateTime, number } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { useQueryState } from '@/lib/use';

interface Row {
    id: string; name: string; email: string; job_title: string | null; is_platform: boolean; role: string | null; role_name: string | null;
    role_color: string; property_access: string | null; properties_count: number; status: string; two_factor: boolean; last_login_at: string | null;
}
interface Detail {
    id: string; name: string; email: string; phone_e164: string | null; job_title: string | null; locale: string; avatar: string | null;
    is_platform: boolean; roles: string[]; role_name: string | null; role_color: string | null; role_description: string | null; status: string;
    two_factor: boolean; last_login_at: string | null; created_at: string | null; permissions: string[];
    properties: { code: string; name: string; city: string | null; role_name: string; status: string; is_owner: boolean }[];
    activity: ActivityItem[];
}
interface Props {
    rows: Row[]; meta: PageMeta;
    counts: Record<string, number>;
    kpis: { total: number; active: number; inactive: number; roles: number };
    filters: { q: string; role: string; property: string; status: string; kind: string };
    selected: string;
    options: { roles: Option[]; platform_roles: (Option & { description: string | null })[]; properties: Option[]; statuses: string[] };
    catalogue: PermissionGroup[];
    open_new: boolean;
    locales: Record<string, string>;
    me: string;
}

function UserForm({ user, options, locales, onClose }: { user: Detail | null; options: Props['options']; locales: Record<string, string>; onClose: () => void }) {
    const [data, setData] = useState({
        name: user?.name ?? '', email: user?.email ?? '', job_title: user?.job_title ?? '', phone_e164: user?.phone_e164 ?? '',
        locale: user?.locale ?? 'en', roles: user?.roles ?? [],
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const editsRoles = !user || user.is_platform;

    const save = async () => {
        setBusy(true);
        setError(null);
        const body: Record<string, unknown> = { name: data.name, job_title: data.job_title || null, phone_e164: data.phone_e164 || null, locale: data.locale };
        if (editsRoles) body.roles = data.roles;
        try {
            const res = user
                ? await http.put<{ message: string; user: Detail }>(`/web-api/admin/users/${user.id}`, body)
                : await http.post<{ message: string; user: Detail }>('/web-api/admin/users', { ...body, email: data.email });
            toast.success(res.message);
            navigateWithQuery({ selected: res.user.id, new: null }, false);
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
        }
    };

    return (
        <Drawer open title={user ? t('users.edit_user') : t('admin.add_user')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{user ? t('ui.save_changes') : t('admin.add_user')}</Button>
        </>}>
            {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
            {!user && <Alert tone="info">{t('users.platform_invite_hint')}</Alert>}
            <FormSection title={t('users.section_details')}>
                <Input fieldClass="span-12" label={t('users.name')} required value={data.name} onChange={(e) => setData({ ...data, name: e.target.value })} error={error?.field('name')} />
                <Input fieldClass="span-12" label={t('users.email')} required type="email" icon="mail" disabled={!!user} value={data.email} onChange={(e) => setData({ ...data, email: e.target.value })} error={error?.field('email')} />
                <Input fieldClass="span-6" label={t('users.job_title')} optional value={data.job_title} onChange={(e) => setData({ ...data, job_title: e.target.value })} error={error?.field('job_title')} />
                <Input fieldClass="span-6" label={t('users.phone')} optional icon="phone" placeholder="+971501234567" value={data.phone_e164} onChange={(e) => setData({ ...data, phone_e164: e.target.value })} error={error?.field('phone_e164')} />
                <Select fieldClass="span-6" label={t('users.language')} value={data.locale} options={Object.entries(locales).map(([value, label]) => ({ value, label }))} onChange={(e) => setData({ ...data, locale: e.target.value })} />
            </FormSection>
            {editsRoles && (
                <FormSection title={t('users.platform_roles')}>
                    <Field className="span-12" label={t('users.platform_roles')} required error={error?.field('roles')}>
                        <div className="stack" style={{ gap: 10 }}>
                            {options.platform_roles.map((r) => (
                                <div key={r.value}>
                                    <Checkbox label={<span className="strong">{r.label}</span>} checked={data.roles.includes(String(r.value))}
                                        onChange={(e) => setData({ ...data, roles: e.target.checked ? [...data.roles, String(r.value)] : data.roles.filter((x) => x !== r.value) })} />
                                    {r.description && <div className="muted text-sm" style={{ marginLeft: 28 }}>{r.description}</div>}
                                </div>
                            ))}
                        </div>
                    </Field>
                </FormSection>
            )}
        </Drawer>
    );
}

function Panel({ id, me, catalogue, onClose, onEdit }: { id: string; me: string; catalogue: PermissionGroup[]; onClose: () => void; onEdit: (u: Detail) => void }) {
    const [user, setUser] = useState<Detail | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [tab, setTab] = useState('overview');
    const [confirm, setConfirm] = useState<'disabled' | 'active' | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        setUser(null);
        setFailed(null);
        http.get<{ user: Detail }>(`/web-api/admin/users/${id}`).then((r) => alive && setUser(r.user)).catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id]);

    if (failed) return <SidePanel title={t('users.user')} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!user) return <SidePanel title={t('ui.loading')} onClose={onClose}><div className="sp-section stack"><div className="skeleton" style={{ height: 72 }} /><div className="skeleton" style={{ height: 200 }} /></div></SidePanel>;

    const self = user.id === me;
    const act = async (fn: () => Promise<{ message: string }>, reload: boolean) => {
        setBusy(true);
        try {
            const res = await fn();
            toast.success(res.message);
            if (reload) window.location.reload();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        }
        setBusy(false);
        setConfirm(null);
    };

    return (
        <SidePanel title={user.name} onClose={onClose}>
            <UserHeader name={user.name} avatar={user.avatar} status={user.status} roleName={user.role_name} roleDescription={user.role_description}
                action={<Button size="sm" variant="outline" icon="pencil" onClick={() => onEdit(user)}>{t('users.edit_user')}</Button>} />
            <div style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={[
                    { key: 'overview', label: t('users.tabs.overview') },
                    { key: 'permissions', label: t('users.tabs.permissions') },
                    { key: 'properties', label: t('users.tabs.properties'), count: user.properties.length },
                    { key: 'activity', label: t('users.tabs.activity') },
                ]} />
            </div>
            <div className="sp-section" style={{ borderTop: 0 }}>
                {tab === 'overview' && (
                    <KeyValue items={[
                        { icon: 'user', label: t('users.name'), value: user.name },
                        { icon: 'mail', label: t('users.email'), value: user.email },
                        { icon: 'phone', label: t('users.phone'), value: user.phone_e164 },
                        { icon: 'user-cog', label: t('users.role'), value: <RoleBadge name={user.role_name ?? (user.is_platform ? null : t('users.property_user'))} color={user.role_color} /> },
                        { icon: 'activity', label: t('users.status'), value: <Badge status={user.status} /> },
                        { icon: 'log-in', label: t('users.last_login'), value: user.last_login_at ? dateTime(user.last_login_at) : t('ui.never') },
                        { icon: 'calendar-plus', label: t('users.created_on'), value: dateTime(user.created_at) },
                        { icon: 'shield-check', label: t('users.two_factor'), value: <Badge status={user.two_factor ? 'enabled' : 'disabled'} /> },
                    ]} />
                )}
                {tab === 'permissions' && (user.permissions.length > 0
                    ? <PermissionView catalogue={catalogue} granted={user.permissions} />
                    : <EmptyState icon="shield" title={t('users.no_platform_permissions')} />)}
                {tab === 'properties' && (user.properties.length === 0 ? <EmptyState icon="hotel" title={user.is_platform ? t('users.all_properties') : t('property.no_properties')} /> : (
                    <ul className="list-plain">
                        {user.properties.map((p) => (
                            <li key={p.code} className="activity">
                                <span className="a-icon tone-blue"><Icon name="hotel" size={16} /></span>
                                <div className="grow"><div className="a-title">{p.name}{p.is_owner && <> · <span className="muted">{t('users.owner')}</span></>}</div><div className="a-sub">{p.code}{p.city && ` · ${p.city}`} · {p.role_name}</div></div>
                                <Badge size="sm" status={p.status} />
                            </li>
                        ))}
                    </ul>
                ))}
                {tab === 'activity' && <ActivityList items={user.activity} />}
            </div>
            <div className="sp-section">
                <h3>{t('ui.quick_actions')}</h3>
                <div className="action-grid">
                    <Button variant="outline" icon="key" disabled={busy} onClick={() => act(() => http.post(`/web-api/admin/users/${user.id}/password-link`), false)}>{t('users.reset_password')}</Button>
                    {user.status === 'disabled' || user.status === 'locked'
                        ? <Button variant="outline" icon="check-circle" disabled={busy || self} onClick={() => setConfirm('active')}>{t('users.enable_user')}</Button>
                        : <Button variant="danger-soft" icon="pause" disabled={busy || self} onClick={() => setConfirm('disabled')}>{t('users.disable_user')}</Button>}
                </div>
            </div>
            <ConfirmDialog open={confirm !== null} danger={confirm === 'disabled'} busy={busy}
                title={confirm === 'disabled' ? t('users.disable_user') : t('users.enable_user')}
                message={t(confirm === 'disabled' ? 'users.disable_confirm' : 'users.enable_confirm', { name: user.name })}
                onConfirm={() => act(() => http.post(`/web-api/admin/users/${user.id}/status`, { status: confirm }), true)} onClose={() => setConfirm(null)} />
        </SidePanel>
    );
}

/** Super Admin → every account on the platform. */
function AdminUsersPage(props: Props) {
    const [selected, setSelected] = useQueryState('selected', props.selected || props.rows[0]?.id || '');
    const [q, setQ] = useState(props.filters.q);
    const [form, setForm] = useState<{ user: Detail | null } | null>(props.open_new ? { user: null } : null);
    const f = props.filters;

    const columns: Column<Row>[] = [
        { key: 'name', header: t('users.user'), sortable: true, width: 220, render: (r) => <UserCell name={r.name} title={r.job_title} /> },
        { key: 'email', header: t('users.email_short'), sortable: true, render: (r) => r.email },
        { key: 'role', header: t('users.role'), render: (r) => <RoleBadge name={r.role_name} color={r.role_color} /> },
        { key: 'access', header: t('users.property_access'), render: (r) => r.property_access === 'all' ? t('users.all_properties') : r.property_access ? <>{r.property_access}{r.properties_count > 1 && <span className="muted"> +{r.properties_count - 1}</span>}</> : '—' },
        { key: 'status', header: t('users.status'), sortable: true, render: (r) => <Badge status={r.status} /> },
        { key: 'last_login', header: t('users.last_login'), sortable: true, render: (r) => <span className="nowrap">{r.last_login_at ? dateTime(r.last_login_at) : t('ui.never')}</span> },
        { key: 'actions', header: t('ui.actions'), className: 'col-actions', render: (r) => <RowMenu items={[{ label: t('ui.view'), icon: 'eye', onClick: () => setSelected(r.id) }]} /> },
    ];

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('users.title')} description={t('users.platform_subtitle')}
                    actions={<Button variant="primary" icon="plus" onClick={() => setForm({ user: null })}>{t('admin.add_user')}</Button>} />
                <div className="kpi-row">
                    <KpiCard icon="users" tone="blue" label={t('users.total_users')} value={number(props.kpis.total)} />
                    <KpiCard icon="user-check" tone="green" label={t('users.active_users')} value={number(props.kpis.active)} />
                    <KpiCard icon="pause" tone="slate" label={t('users.inactive_users')} value={number(props.kpis.inactive)} />
                    <KpiCard icon="user-cog" tone="violet" label={t('users.roles_count')} value={number(props.kpis.roles)} />
                </div>
                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('users.search_placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('users.role')} value={f.role} placeholder={t('users.all_roles')} options={props.options.roles} onChange={(e) => navigateWithQuery({ role: e.target.value })} />
                    <Select label={t('property.property')} value={f.property} placeholder={t('users.all_properties_filter')} options={props.options.properties} onChange={(e) => navigateWithQuery({ property: e.target.value })} />
                    <Select label={t('users.account_type')} value={f.kind} placeholder={t('ui.all')} options={[{ value: 'platform', label: t('users.kind_platform') }, { value: 'property', label: t('users.kind_property') }]} onChange={(e) => navigateWithQuery({ kind: e.target.value })} />
                    {(f.q || f.role || f.property || f.status || f.kind) && (
                        <button type="button" className="btn btn-ghost" onClick={() => navigateWithQuery({ q: null, role: null, property: null, status: null, kind: null })}><Icon name="x" size={16} />{t('ui.reset')}</button>
                    )}
                </form>
                <PillTabs active={f.status || 'all'} onChange={(k) => navigateWithQuery({ status: k === 'all' ? null : k })}
                    items={[{ key: 'all', label: t('ui.all'), count: props.counts.all ?? 0 }, ...props.options.statuses.map((s) => ({ key: s, label: t(`ui.status.${s}`), count: props.counts[s] ?? 0 }))]} />
                <DataTable columns={columns} rows={props.rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected} />
                <Pagination meta={props.meta} label={t('users.users')} />
            </div>
            {selected && <Panel id={selected} me={props.me} catalogue={props.catalogue} onClose={() => setSelected(null)} onEdit={(u) => setForm({ user: u })} />}
            {form && <UserForm user={form.user} options={props.options} locales={props.locales} onClose={() => setForm(null)} />}
        </div>
    );
}

createPage(AdminUsersPage);
