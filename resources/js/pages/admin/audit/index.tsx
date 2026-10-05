import { useState } from 'react';
import { Button, DataTable, Icon, Input, KeyValue, Modal, PageHeader, Pagination, Select, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime } from '@/lib/format';
import { navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Entry {
    id: string; action: string; action_label: string; user: string | null; user_email: string | null; property: string | null; property_code: string | null;
    entity: string | null; ip: string | null; ref: string | null; changes: unknown; at: string | null;
}
interface Props { rows: Entry[]; meta: PageMeta; filters: { action: string; user: string; property: string; from: string; to: string }; actions: Option[] }

/** Super Admin → audit trail of the whole platform. */
function AuditPage({ rows, meta, filters, actions }: Props) {
    const [f, setF] = useState(filters);
    const [open, setOpen] = useState<Entry | null>(null);
    const active = Object.values(filters).some((v) => v !== '');

    const columns: Column<Entry>[] = [
        { key: 'at', header: t('admin.audit_cols.time'), render: (e) => <span className="nowrap">{dateTime(e.at)}</span> },
        { key: 'user', header: t('admin.audit_cols.user'), render: (e) => e.user ? <div><div className="cell-main">{e.user}</div><div className="cell-sub">{e.user_email}</div></div> : <span className="muted">{t('admin.system_actor')}</span> },
        { key: 'property', header: t('admin.audit_cols.property'), render: (e) => e.property ? <div><div>{e.property}</div><div className="cell-sub">{e.property_code}</div></div> : '—' },
        { key: 'action', header: t('admin.audit_cols.action'), render: (e) => <span className="strong">{e.action_label}</span> },
        { key: 'entity', header: t('admin.audit_cols.entity'), render: (e) => e.entity ?? '—' },
        { key: 'ip', header: t('admin.audit_cols.ip'), render: (e) => e.ip ?? '—' },
        { key: 'details', header: '', className: 'col-actions', render: (e) => <Button size="sm" variant="ghost" icon="eye" aria-label={t('ui.details')} title={t('ui.details')} onClick={(ev) => { ev.stopPropagation(); setOpen(e); }} /> },
    ];

    return (
        <div className="content">
            <PageHeader title={t('admin.audit_title')} description={t('admin.audit_sub')} />
            <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery(f); }}>
                <Select label={t('admin.audit_cols.action')} value={f.action} placeholder={t('ui.all')} options={actions} onChange={(e) => setF({ ...f, action: e.target.value })} />
                <Input label={t('admin.audit_cols.user')} icon="search" placeholder={t('admin.audit_user_placeholder')} value={f.user} onChange={(e) => setF({ ...f, user: e.target.value })} />
                <Input label={t('property.code')} placeholder="P1001" value={f.property} onChange={(e) => setF({ ...f, property: e.target.value.toUpperCase() })} />
                <Input label={t('ui.from_date')} type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <Input label={t('ui.to_date')} type="date" value={f.to} min={f.from} onChange={(e) => setF({ ...f, to: e.target.value })} />
                <Button type="submit" variant="primary" icon="filter">{t('ui.apply')}</Button>
                {active && <button type="button" className="btn btn-ghost" onClick={() => navigateWithQuery({ action: null, user: null, property: null, from: null, to: null })}><Icon name="x" size={16} />{t('ui.reset')}</button>}
            </form>
            <DataTable columns={columns} rows={rows} rowKey={(e) => e.id} onRowClick={setOpen} />
            <Pagination meta={meta} label={t('admin.entries')} />
            <Modal open={!!open} size="lg" title={open?.action_label ?? ''} onClose={() => setOpen(null)}>
                {open && (
                    <div className="stack">
                        <KeyValue items={[
                            { label: t('admin.audit_cols.time'), value: dateTime(open.at) },
                            { label: t('admin.audit_cols.user'), value: open.user ? `${open.user} (${open.user_email})` : t('admin.system_actor') },
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
