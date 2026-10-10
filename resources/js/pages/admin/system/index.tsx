import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, Icon, Input, KpiCard, PageHeader, Pagination, PillTabs, Select, toast, type Column, type PageMeta } from '@/components/ui';
import { EmailCard, type EmailSettings } from '@/components/property/EmailCard';
import { createPage } from '@/lib/boot';
import { dateTime, number, relative } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface ErrorGroup {
    id: string; level: string; source: string; class: string | null; message: string; location: string | null; count: number;
    first_seen: string | null; last_seen: string | null; ref: string | null; resolved_at: string | null;
}
interface Props {
    rows: ErrorGroup[]; meta: PageMeta; counts: { open: number; resolved: number; all: number };
    filters: { status: string; level: string; source: string; q: string };
    email: EmailSettings | null;
    overview: { open_errors: number; errors_24h: number; expiring_subscriptions: number; onboarding_properties: number; connected_channels: number };
}

/** Super Admin → grouped application errors. */
function SystemPage({ rows, meta, counts, filters, overview, email }: Props) {
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState<string | null>(null);

    const resolve = async (id: string) => {
        setBusy(id);
        try {
            const res = await http.post<{ message: string }>(`/web-api/admin/system/errors/${id}/resolve`);
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setBusy(null);
        }
    };

    const columns: Column<ErrorGroup>[] = [
        { key: 'level', header: t('admin.error_cols.level'), render: (e) => <Badge status={e.resolved_at ? 'resolved' : e.level}>{e.resolved_at ? undefined : t(`ui.status.${e.level}`)}</Badge> },
        { key: 'source', header: t('admin.error_cols.source'), render: (e) => t(`admin.sources.${e.source}`) },
        { key: 'message', header: t('admin.error_cols.message'), render: (e) => <div style={{ maxWidth: 520 }}><div className="cell-main ellipsis" title={e.message}>{e.message}</div><div className="cell-sub">{[e.class, e.location].filter(Boolean).join(' · ')}</div></div> },
        { key: 'count', header: t('admin.error_cols.count'), sortable: true, align: 'right', render: (e) => number(e.count) },
        { key: 'last_seen', header: t('admin.error_cols.last_seen'), sortable: true, render: (e) => <span className="nowrap" title={dateTime(e.last_seen)}>{relative(e.last_seen)}</span> },
        { key: 'ref', header: t('admin.error_cols.ref'), render: (e) => <code>{e.ref ?? '—'}</code> },
        { key: 'actions', header: t('ui.actions'), className: 'col-actions', render: (e) => e.resolved_at ? <span className="muted text-sm">{dateTime(e.resolved_at)}</span> : <Button size="sm" variant="outline" icon="check" loading={busy === e.id} onClick={() => resolve(e.id)}>{t('admin.mark_resolved')}</Button> },
    ];

    return (
        <div className="content">
            <PageHeader title={t('admin.system_title')} description={t('admin.system_sub')} />
            <div className="kpi-row">
                <KpiCard icon={overview.open_errors > 0 ? 'circle-alert' : 'check-circle'} tone={overview.open_errors > 0 ? 'red' : 'green'} label={t('admin.open_errors_label')} value={number(overview.open_errors)} />
                <KpiCard icon="activity" tone="amber" label={t('admin.errors_24h')} value={number(overview.errors_24h)} />
                <KpiCard icon="credit-card" tone="violet" label={t('admin.upcoming_expirations')} value={number(overview.expiring_subscriptions)} sub={t('admin.subscriptions_expiring')} />
                <KpiCard icon="clock" tone="blue" label={t('admin.pending_setup')} value={number(overview.onboarding_properties)} sub={t('admin.properties_in_setup')} />
            </div>
            {email && <div style={{ marginBottom: 20 }}><EmailCard initial={email} disabled={false} url="/web-api/admin/email" scope="platform" /></div>}
            <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('admin.error_search')} value={q} onChange={(e) => setQ(e.target.value)} />
                <Select label={t('admin.error_cols.level')} value={filters.level} placeholder={t('ui.all')} options={['warning', 'error', 'critical'].map((l) => ({ value: l, label: t(`ui.status.${l}`) }))} onChange={(e) => navigateWithQuery({ level: e.target.value })} />
                <Select label={t('admin.error_cols.source')} value={filters.source} placeholder={t('ui.all')} options={['server', 'client', 'queue', 'scheduler'].map((s) => ({ value: s, label: t(`admin.sources.${s}`) }))} onChange={(e) => navigateWithQuery({ source: e.target.value })} />
                {(filters.q || filters.level || filters.source) && <button type="button" className="btn btn-ghost" onClick={() => navigateWithQuery({ q: null, level: null, source: null })}><Icon name="x" size={16} />{t('ui.reset')}</button>}
            </form>
            <PillTabs active={filters.status || 'open'} onChange={(k) => navigateWithQuery({ status: k === 'open' ? null : k })} items={[
                { key: 'open', label: t('admin.tab_open'), count: counts.open },
                { key: 'resolved', label: t('ui.status.resolved'), count: counts.resolved },
                { key: 'all', label: t('ui.all'), count: counts.all },
            ]} />
            <DataTable columns={columns} rows={rows} rowKey={(e) => e.id} empty={<EmptyState icon="check-circle" title={t('admin.no_errors')} />} />
            <Pagination meta={meta} label={t('admin.error_groups')} />
        </div>
    );
}

createPage(SystemPage);
