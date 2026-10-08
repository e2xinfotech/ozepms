import { useState } from 'react';
import { Badge, Button, DataTable, Icon, Input, KeyValue, Modal, PageHeader, Pagination, PillTabs, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Entry {
    id: string; action: string; action_label: string; user: string | null; user_email: string | null; property: string | null; property_code: string | null;
    entity: string | null; ip: string | null; ref: string | null; changes: unknown; at: string | null;
    impersonator: string | null; impersonator_email: string | null;
}
interface RequestRow {
    id: string; method: string; route: string; uri: string; params: Record<string, string> | null; fields: string[]; status: number;
    user: string | null; user_email: string | null; impersonator: string | null; impersonator_email: string | null;
    property_code: string | null; ip: string | null; ref: string | null; at: string | null;
}
interface Filters { action?: string; user: string; property: string; from: string; to: string; via: string; method?: string }
interface Props { view: 'changes' | 'requests'; rows: (Entry | RequestRow)[]; meta: PageMeta; filters: Filters; actions: Option[] }

const EMPTY_FILTERS = { action: null, user: null, property: null, from: null, to: null, via: null, method: null };

function WhoCell({ name, email, system }: { name: string | null; email: string | null; system: string }) {
    return name ? <div><div className="cell-main">{name}</div><div className="cell-sub">{email}</div></div> : <span className="muted">{system}</span>;
}

function ViaCell({ name, email }: { name: string | null; email: string | null }) {
    return name ? <div title={t('admin.audit_acting_via', { name })}><div className="cell-main">{name}</div><div className="cell-sub">{email}</div></div> : <span className="muted">—</span>;
}

/** Super Admin → audit trail of the whole platform. */
function AuditPage({ view, rows, meta, filters, actions }: Props) {
    const [f, setF] = useState({ action: '', method: '', ...filters });
    const [open, setOpen] = useState<Entry | null>(null);
    const active = Object.values(filters).some((v) => v !== '');
    const requests = view === 'requests';

    const columns: Column<Entry>[] = [
        { key: 'at', header: t('admin.audit_cols.time'), render: (e) => <span className="nowrap">{dateTime(e.at)}</span> },
        { key: 'user', header: t('admin.audit_cols.user'), render: (e) => e.user ? <div><div className="cell-main">{e.user}</div><div className="cell-sub">{e.user_email}</div></div> : <span className="muted">{t('admin.system_actor')}</span> },
        { key: 'via', header: t('admin.audit_request_cols.via'), render: (e) => <ViaCell name={e.impersonator} email={e.impersonator_email} /> },
        { key: 'property', header: t('admin.audit_cols.property'), render: (e) => e.property ? <div><div>{e.property}</div><div className="cell-sub">{e.property_code}</div></div> : '—' },
        { key: 'action', header: t('admin.audit_cols.action'), render: (e) => <span className="strong">{e.action_label}</span> },
        { key: 'entity', header: t('admin.audit_cols.entity'), render: (e) => e.entity ?? '—' },
        { key: 'ip', header: t('admin.audit_cols.ip'), render: (e) => e.ip ?? '—' },
        { key: 'details', header: '', className: 'col-actions', render: (e) => <Button size="sm" variant="ghost" icon="eye" aria-label={t('ui.details')} title={t('ui.details')} onClick={(ev) => { ev.stopPropagation(); setOpen(e); }} /> },
    ];

    const requestColumns: Column<RequestRow>[] = [
        { key: 'at', header: t('admin.audit_cols.time'), render: (r) => <span className="nowrap">{dateTime(r.at)}</span> },
        { key: 'user', header: t('admin.audit_cols.user'), render: (r) => <WhoCell name={r.user} email={r.user_email} system={t('admin.system_actor')} /> },
        { key: 'via', header: t('admin.audit_request_cols.via'), render: (r) => <ViaCell name={r.impersonator} email={r.impersonator_email} /> },
        { key: 'property', header: t('admin.audit_cols.property'), render: (r) => r.property_code ?? '—' },
        { key: 'route', header: t('admin.audit_request_cols.route'), render: (r) => <div><div className="cell-main"><span className="strong">{r.method}</span> {r.route}</div>{r.params && <div className="cell-sub">{Object.values(r.params).join(' · ')}</div>}</div> },
        { key: 'fields', header: t('admin.audit_request_cols.fields'), render: (r) => <span className="cell-sub">{r.fields.slice(0, 6).join(', ')}{r.fields.length > 6 ? ` +${r.fields.length - 6}` : ''}</span> },
        { key: 'status', header: t('admin.audit_request_cols.status'), render: (r) => <Badge tone={r.status < 300 ? 'green' : r.status < 500 ? 'amber' : 'red'}>{r.status}</Badge> },
        { key: 'ip', header: t('admin.audit_cols.ip'), render: (r) => r.ip ?? '—' },
    ];

    return (
        <div className="content">
            <PageHeader title={t('admin.audit_title')} description={requests ? t('admin.audit_requests_sub') : t('admin.audit_sub')} />
            <PillTabs active={view} onChange={(k) => navigateWithQuery({ view: k === 'requests' ? 'requests' : null, ...EMPTY_FILTERS, page: null })}
                items={[{ key: 'changes', label: t('admin.audit_tabs.changes') }, { key: 'requests', label: t('admin.audit_tabs.requests') }]} />
            <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ ...f, view: requests ? 'requests' : null, page: null }); }}>
                {!requests && <Select label={t('admin.audit_cols.action')} value={f.action} placeholder={t('ui.all')} options={actions} onChange={(e) => setF({ ...f, action: e.target.value })} />}
                {requests && <Select label={t('admin.audit_request_cols.method')} value={f.method} placeholder={t('ui.all')} options={['POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m }))} onChange={(e) => setF({ ...f, method: e.target.value })} />}
                <Input label={t('admin.audit_cols.user')} icon="search" placeholder={t('admin.audit_user_placeholder')} value={f.user} onChange={(e) => setF({ ...f, user: e.target.value })} />
                <Input label={t('admin.audit_via')} placeholder={t('admin.audit_via_placeholder')} value={f.via} onChange={(e) => setF({ ...f, via: e.target.value })} />
                <Input label={t('property.code')} placeholder="P1001" value={f.property} onChange={(e) => setF({ ...f, property: e.target.value.toUpperCase() })} />
                <Input label={t('ui.from_date')} type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <Input label={t('ui.to_date')} type="date" value={f.to} min={f.from} onChange={(e) => setF({ ...f, to: e.target.value })} />
                <Button type="submit" variant="primary" icon="filter">{t('ui.apply')}</Button>
                {active && <button type="button" className="btn btn-ghost" onClick={() => navigateWithQuery(EMPTY_FILTERS)}><Icon name="x" size={16} />{t('ui.reset')}</button>}
            </form>
            {requests
                ? <DataTable columns={requestColumns} rows={rows as RequestRow[]} rowKey={(r) => r.id} />
                : <DataTable columns={columns} rows={rows as Entry[]} rowKey={(e) => e.id} onRowClick={setOpen} />}
            <Pagination meta={meta} label={t('admin.entries')} />
            <Modal open={!!open} size="lg" title={open?.action_label ?? ''} onClose={() => setOpen(null)}>
                {open && (
                    <div className="stack">
                        <KeyValue items={[
                            { label: t('admin.audit_cols.time'), value: dateTime(open.at) },
                            { label: t('admin.audit_cols.user'), value: open.user ? `${open.user} (${open.user_email})` : t('admin.system_actor') },
                            { label: t('admin.audit_request_cols.via'), value: open.impersonator ? `${open.impersonator} (${open.impersonator_email})` : null },
                            { label: t('admin.audit_cols.property'), value: open.property ? `${open.property} (${open.property_code})` : null },
                            { label: t('admin.audit_cols.action'), value: <code>{open.action}</code> },
                            { label: t('admin.audit_cols.entity'), value: open.entity },
                            { label: t('admin.audit_cols.ip'), value: open.ip },
                            { label: t('errors.reference'), value: open.ref },
                        ]} />
                        {open.changes !== null && open.changes !== undefined && (
                            <pre className="code-box" style={{ whiteSpace: 'pre-wrap', margin: 0, maxHeight: 360, overflow: 'auto' }}>{JSON.stringify(open.changes, null, 2)}</pre>
                        )}
                    </div>
                )}
            </Modal>
        </div>
    );
}

createPage(AuditPage);
