import { useState } from 'react';
import { Badge, Button, DataTable, Icon, Input, KpiCard, PageHeader, Pagination, PillTabs, RowMenu, Select, toast, type Column, type PageMeta } from '@/components/ui';
import { RoleBadge, UserCell } from '@/components/users/UserBits';
import { createPage } from '@/lib/boot';
import { dateTime, number } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { propertyApiUrl } from '@/lib/page';
import { t } from '@/lib/i18n';
import { useQueryState } from '@/lib/use';
import { RoleManager } from './_components/RoleManager';
import { UserDrawer } from './_components/UserDrawer';
import { UserPanel } from './_components/UserPanel';
import type { PermissionGroup, RoleInfo, UserDetail, UserRow } from './_components/types';

interface Props {
    rows: UserRow[];
    meta: PageMeta;
    counts: { all: number; active: number; disabled: number; invited: number };
    kpis: { total: number; active: number; inactive: number; roles: number };
    filters: { q: string; role: string; status: string };
    selected: string;
    roles: RoleInfo[];
    catalogue: PermissionGroup[];
    colors: string[];
    locales: Record<string, string>;
    me: string;
}

/** Users & Roles of the current property. */
function UsersPage(props: Props) {
    const [selected, setSelected] = useQueryState('selected', props.selected || props.rows[0]?.id || '');
    const [q, setQ] = useState(props.filters.q);
    const [drawer, setDrawer] = useState<{ user: UserDetail | null } | null>(null);
    const [rolesOpen, setRolesOpen] = useState(false);
    const [checked, setChecked] = useState<Set<string>>(new Set());

    const openEditor = async (id: string) => {
        try {
            const res = await http.get<{ user: UserDetail }>(propertyApiUrl(`/users/${id}`));
            setDrawer({ user: res.user });
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        }
    };

    const columns: Column<UserRow>[] = [
        { key: 'name', header: t('users.user'), sortable: true, width: 220, render: (r) => <UserCell name={r.name} title={r.job_title} /> },
        { key: 'email', header: t('users.email_short'), sortable: true, render: (r) => r.email },
        { key: 'role', header: t('users.role'), sortable: true, render: (r) => <span className="row" style={{ gap: 6 }}><RoleBadge name={r.role_name} color={r.role_color} />{r.is_owner && <Icon name="star" size={14} />}</span> },
        { key: 'status', header: t('users.status'), sortable: true, render: (r) => <Badge status={r.invited && r.status === 'active' ? 'invited' : r.status} /> },
        { key: 'last_login', header: t('users.last_login'), sortable: true, render: (r) => <span className="nowrap">{r.last_login_at ? dateTime(r.last_login_at) : t('ui.never')}</span> },
        {
            key: 'actions', header: t('ui.actions'), className: 'col-actions', render: (r) => (
                <RowMenu items={[
                    { label: t('ui.view'), icon: 'eye', onClick: () => setSelected(r.id) },
                    { label: t('users.edit_user'), icon: 'pencil', onClick: () => openEditor(r.id) },
                ]} />
            ),
        },
    ];

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('users.title')} description={t('users.subtitle')} actions={<>
                    <Button variant="outline" icon="settings" onClick={() => setRolesOpen(true)}>{t('roles.role_management')}</Button>
                    <Button variant="primary" icon="plus" onClick={() => setDrawer({ user: null })}>{t('users.add_user')}</Button>
                </>} />

                <div className="kpi-row">
                    <KpiCard icon="users" tone="blue" label={t('users.total_users')} value={number(props.kpis.total)} />
                    <KpiCard icon="user-check" tone="green" label={t('users.active_users')} value={number(props.kpis.active)} />
                    <KpiCard icon="pause" tone="slate" label={t('users.inactive_users')} value={number(props.kpis.inactive)} />
                    <KpiCard icon="user-cog" tone="violet" label={t('users.roles_count')} value={number(props.kpis.roles)} />
                </div>

                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('users.search_placeholder')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('users.role')} value={props.filters.role} placeholder={t('users.all_roles')} options={props.roles.map((r) => ({ value: r.code, label: r.name }))} onChange={(e) => navigateWithQuery({ role: e.target.value })} />
                    <Select label={t('users.status')} value={props.filters.status} placeholder={t('users.all_statuses')} options={['active', 'invited', 'disabled'].map((s) => ({ value: s, label: t(`ui.status.${s}`) }))} onChange={(e) => navigateWithQuery({ status: e.target.value })} />
                    {(props.filters.q || props.filters.role || props.filters.status) && (
                        <button type="button" className="btn btn-ghost" onClick={() => navigateWithQuery({ q: null, role: null, status: null })}><Icon name="x" size={16} />{t('ui.reset')}</button>
                    )}
                </form>

                <PillTabs active={props.filters.status || 'all'} onChange={(k) => navigateWithQuery({ status: k === 'all' ? null : k })} items={[
                    { key: 'all', label: t('ui.all'), count: props.counts.all },
                    { key: 'active', label: t('ui.status.active'), count: props.counts.active },
                    { key: 'invited', label: t('ui.status.invited'), count: props.counts.invited },
                    { key: 'disabled', label: t('ui.status.disabled'), count: props.counts.disabled },
                ]} />

                <DataTable columns={columns} rows={props.rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                    selectable selected={checked} onSelect={setChecked} />
                <Pagination meta={props.meta} label={t('users.users')} />
            </div>

            {selected && <UserPanel id={selected} me={props.me} catalogue={props.catalogue} onClose={() => setSelected(null)} onEdit={(u) => setDrawer({ user: u })} />}
            {drawer && <UserDrawer user={drawer.user} roles={props.roles} locales={props.locales} onClose={() => setDrawer(null)}
                onSaved={(id) => navigateWithQuery({ selected: id }, false)} />}
            {rolesOpen && <RoleManager roles={props.roles} catalogue={props.catalogue} colors={props.colors} onClose={() => setRolesOpen(false)} />}
        </div>
    );
}

createPage(UsersPage);
