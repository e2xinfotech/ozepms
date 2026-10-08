import { Fragment, useEffect, useState } from 'react';
import { Badge, Button, Card, EmptyState, Select } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

interface Log { id: number; time: string; direction: string; type: string; status: string; summary: Record<string, string | number> | null; items: number; attempts: number; error: string | null; request: string | null; response: string | null }
interface Page { rows: Log[]; meta: { page: number; last_page: number; total: number } }

const TONE: Record<string, 'green' | 'red' | 'amber'> = { success: 'green', failed: 'red', retrying: 'amber' };

function describe(l: Log): string {
    const s = l.summary;
    if (!s) return '';
    if ('updates' in s) return t('channels.logs.summary_ari', s);
    if ('booking' in s) return t('channels.logs.summary_booking_done', s);
    if ('ref' in s) return t('channels.logs.summary_booking', s);
    return '';
}

export function LogsTab({ base }: { base: string }) {
    const [direction, setDirection] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Page | null>(null);
    const [open, setOpen] = useState<number | null>(null);

    useEffect(() => {
        let alive = true;
        const q = new URLSearchParams({ page: String(page), ...(direction ? { direction } : {}), ...(status ? { status } : {}) });
        http.get<Page>(`${propertyApiUrl(`${base}/logs`)}?${q}`).then((d) => alive && setData(d)).catch(() => alive && setData({ rows: [], meta: { page: 1, last_page: 1, total: 0 } }));
        return () => { alive = false; };
    }, [base, direction, status, page]);

    return (
        <Card title={t('channels.tabs.logs')}>
            <div className="filter-bar">
                <Select label={t('channels.col.direction')} value={direction} placeholder={t('channels.logs.all_directions')} options={[{ value: 'outbound', label: t('channels.logs.outbound') }, { value: 'inbound', label: t('channels.logs.inbound') }]} onChange={(e) => { setDirection(e.target.value); setPage(1); }} />
                <Select label={t('channels.col.status')} value={status} placeholder={t('channels.logs.all_statuses')} options={['success', 'failed', 'retrying'].map((s) => ({ value: s, label: t(`channels.logs.statuses.${s}`) }))} onChange={(e) => { setStatus(e.target.value); setPage(1); }} />
            </div>
            {data && data.rows.length === 0 && <EmptyState icon="scroll-text" title={t('channels.logs.empty')} />}
            {data && data.rows.length > 0 && (
                <div className="ch-scroll">
                    <table className="ch-map">
                        <thead><tr><th>{t('channels.col.time')}</th><th>{t('channels.col.direction')}</th><th>{t('channels.col.type')}</th><th>{t('channels.col.details')}</th><th>{t('channels.col.status')}</th><th /></tr></thead>
                        <tbody>
                            {data.rows.map((l) => (
                                <Fragment key={l.id}>
                                    <tr>
                                        <td className="num">{dateTime(l.time)}</td>
                                        <td>{t(`channels.logs.${l.direction}`)}</td>
                                        <td>{t(`channels.logs.types.${l.type}`)}</td>
                                        <td>{l.error ?? describe(l)}</td>
                                        <td><Badge size="sm" tone={TONE[l.status] ?? 'slate'}>{t(`channels.logs.statuses.${l.status}`)}</Badge></td>
                                        <td><Button size="sm" variant="ghost" icon={open === l.id ? 'chevron-up' : 'chevron-down'} aria-label={t('channels.col.details')} onClick={() => setOpen(open === l.id ? null : l.id)} /></td>
                                    </tr>
                                    {open === l.id && <tr><td colSpan={6} style={{ padding: 0 }}><div className="ch-log-body">
                                        <div><strong>{t('channels.logs.request')}</strong><pre>{l.request ?? '—'}</pre></div>
                                        <div><strong>{t('channels.logs.response')}</strong><pre>{l.response ?? l.error ?? '—'}</pre></div>
                                    </div></td></tr>}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {data && data.meta.last_page > 1 && (
                <div className="ch-pager">
                    <Button size="sm" icon="chevron-left" disabled={page <= 1} aria-label={t('ui.previous')} onClick={() => setPage(page - 1)} />
                    <span className="num">{data.meta.page} / {data.meta.last_page}</span>
                    <Button size="sm" icon="chevron-right" disabled={page >= data.meta.last_page} aria-label={t('ui.next')} onClick={() => setPage(page + 1)} />
                </div>
            )}
        </Card>
    );
}
