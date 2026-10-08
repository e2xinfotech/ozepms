import { useState } from 'react';
import { Badge, Button, DataTable, EmptyState, Modal, PageHeader, Pagination, PillTabs, Select, Textarea, toast, type Column, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Row {
    id: string; type: string; summary: string; status: string; property_code: string | null; requested_by: string | null; requested_by_email: string | null;
    link: string | null; requested_at: string | null; decided_by: string | null; decided_at: string | null; note: string | null; can_decide: boolean;
}
interface Props { rows: Row[]; meta: PageMeta; filters: { tab: string; type: string }; counts: { pending: number; decided: number }; types: string[] }

function DecisionDialog({ row, approve, onClose }: { row: Row; approve: boolean; onClose: () => void }) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const send = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string }>(`/web-api/admin/approvals/${row.id}/decide`, { decision: approve ? 'approve' : 'reject', note });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
        }
    };

    return (
        <Modal open title={approve ? t('approvals.approve_title') : t('approvals.reject_title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant={approve ? 'primary' : 'danger'} loading={busy} onClick={send}>{approve ? t('approvals.approve') : t('approvals.reject')}</Button>
        </>}>
            <p style={{ marginBottom: 12 }}><span className="strong">{t(`approvals.types.${row.type}`)}</span> · {row.summary}</p>
            <Textarea label={t('approvals.note')} optional={approve} required={!approve} rows={3} maxLength={500} placeholder={t('approvals.note_ph')} value={note}
                onChange={(e) => setNote(e.target.value)} error={error?.field('note') ?? (error && !Object.keys(error.fields).length ? error.message : undefined)} />
        </Modal>
    );
}

/** Admin → requests that need a decision. */
function ApprovalsPage({ rows, meta, filters, counts, types }: Props) {
    const [dialog, setDialog] = useState<{ row: Row; approve: boolean } | null>(null);
    const decided = filters.tab === 'decided';

    const columns: Column<Row>[] = [
        { key: 'type', header: t('approvals.cols.type'), render: (r) => <span className="strong">{t(`approvals.types.${r.type}`)}</span> },
        { key: 'summary', header: t('approvals.cols.summary'), render: (r) => <div><div>{r.link ? <a href={r.link}>{r.summary}</a> : r.summary}</div>{r.property_code && <div className="cell-sub">{r.property_code}</div>}</div> },
        { key: 'by', header: t('approvals.cols.by'), render: (r) => r.requested_by ? <div><div className="cell-main">{r.requested_by}</div><div className="cell-sub">{r.requested_by_email}</div></div> : '—' },
        { key: 'at', header: t('approvals.cols.at'), render: (r) => <span className="nowrap">{dateTime(r.requested_at)}</span> },
        decided
            ? { key: 'decision', header: t('approvals.cols.decided'), render: (r) => <div><Badge status={r.status} /><div className="cell-sub">{r.decided_by} · {dateTime(r.decided_at)}</div>{r.note && <div className="cell-sub">{r.note}</div>}</div> }
            : {
                key: 'actions', header: '', className: 'col-actions', render: (r) => r.can_decide
                    ? <div className="row"><Button size="sm" variant="primary" icon="check" onClick={() => setDialog({ row: r, approve: true })}>{t('approvals.approve')}</Button>
                        <Button size="sm" variant="outline" icon="x" onClick={() => setDialog({ row: r, approve: false })}>{t('approvals.reject')}</Button></div>
                    : <span className="muted text-sm">{t('approvals.you_cannot')}</span>,
            },
    ];

    return (
        <div className="content">
            <PageHeader title={t('approvals.title')} description={t('approvals.sub')} />
            <div className="filter-bar">
                <Select label={t('approvals.cols.type')} value={filters.type} placeholder={t('ui.all')} options={types.map((x) => ({ value: x, label: t(`approvals.types.${x}`) }))} onChange={(e) => navigateWithQuery({ type: e.target.value })} />
            </div>
            <PillTabs active={filters.tab} onChange={(k) => navigateWithQuery({ tab: k === 'decided' ? 'decided' : null })}
                items={[{ key: 'pending', label: t('approvals.tabs.pending'), count: counts.pending }, { key: 'decided', label: t('approvals.tabs.decided'), count: counts.decided }]} />
            {rows.length === 0 ? <EmptyState icon="check-circle" title={t('approvals.empty')} /> : <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} />}
            <Pagination meta={meta} />
            {dialog && <DecisionDialog row={dialog.row} approve={dialog.approve} onClose={() => setDialog(null)} />}
        </div>
    );
}

createPage(ApprovalsPage);
