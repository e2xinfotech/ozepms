import { useEffect, useState } from 'react';
import { Badge, Button, Card, EmptyState, Input, Modal } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act } from '../../_accommodation/shared';

interface Row { id: number; external_ref: string; status: string; version: number; error: string | null; booking_ref: string | null; booking_id: string | null; guest: string; check_in: string | null; check_out: string | null; updated_at: string; can_retry: boolean }
interface Page { rows: Row[]; meta: { page: number; last_page: number } }
const TONE: Record<string, 'green' | 'blue' | 'slate' | 'red'> = { new: 'green', modified: 'blue', cancelled: 'slate', failed: 'red' };

/** Bookings received from the channel; failed imports can be tried again; Test Channel bookings can be changed or cancelled on the channel. */
export function BookingsTab({ base, isTest }: { base: string; isTest: boolean }) {
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Page | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const [editing, setEditing] = useState<Row | null>(null);
    const [dates, setDates] = useState({ check_in: '', check_out: '' });
    const [version, setVersion] = useState(0);

    useEffect(() => {
        let alive = true;
        http.get<Page>(`${propertyApiUrl(`${base}/bookings`)}?page=${page}`).then((d) => alive && setData(d)).catch(() => alive && setData({ rows: [], meta: { page: 1, last_page: 1 } }));
        return () => { alive = false; };
    }, [base, page, version]);

    const call = async (id: number, path: string, body?: object) => {
        setBusy(id);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`${base}${path}`), body));
        setBusy(null);
        if (res) { setEditing(null); setVersion((v) => v + 1); }
    };

    if (data && data.rows.length === 0) return <Card title={t('channels.tabs.bookings')}><EmptyState icon="calendar-check" title={t('channels.bookings.empty')} /></Card>;

    return (
        <Card title={t('channels.tabs.bookings')}>
            <div className="ch-scroll">
                <table className="ch-map">
                    <thead><tr><th>{t('channels.col.channel_ref')}</th><th>{t('channels.col.booking')}</th><th>{t('channels.col.guest')}</th><th>{t('channels.col.dates')}</th><th>{t('channels.col.version')}</th><th>{t('channels.col.status')}</th><th /></tr></thead>
                    <tbody>
                        {(data?.rows ?? []).map((r) => (
                            <tr key={r.id}>
                                <td className="num">{r.external_ref}</td>
                                <td>{r.booking_ref ? <a href={propertyUrl(`/reservations/${r.booking_id}`)} className="num">{r.booking_ref}</a> : '—'}</td>
                                <td>{r.guest || t('channels.guest_unknown')}</td>
                                <td className="num">{r.check_in && r.check_out ? `${r.check_in} → ${r.check_out}` : '—'}</td>
                                <td className="num">{r.version}</td>
                                <td title={r.error ?? dateTime(r.updated_at)}><Badge size="sm" tone={TONE[r.status]}>{t(`channels.bookings.statuses.${r.status}`)}</Badge>{r.error && <div className="muted text-sm">{r.error}</div>}</td>
                                <td><span className="row" style={{ justifyContent: 'flex-end' }}>
                                    {r.can_retry && <Button size="sm" variant="outline" icon="refresh" loading={busy === r.id} onClick={() => call(r.id, `/bookings/${r.id}/retry`)}>{t('channels.actions.retry')}</Button>}
                                    {isTest && r.status !== 'cancelled' && r.status !== 'failed' && <>
                                        <Button size="sm" variant="outline" icon="calendar-days" onClick={() => { setEditing(r); setDates({ check_in: r.check_in ?? '', check_out: r.check_out ?? '' }); }}>{t('channels.test_channel.modify')}</Button>
                                        <Button size="sm" variant="ghost" icon="x" loading={busy === r.id} onClick={() => call(r.id, `/test-channel/bookings/${r.id}/cancel`)}>{t('channels.test_channel.cancel')}</Button>
                                    </>}
                                </span></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {data && data.meta.last_page > 1 && (
                <div className="ch-pager">
                    <Button size="sm" icon="chevron-left" disabled={page <= 1} aria-label={t('ui.previous')} onClick={() => setPage(page - 1)} />
                    <span className="num">{data.meta.page} / {data.meta.last_page}</span>
                    <Button size="sm" icon="chevron-right" disabled={page >= data.meta.last_page} aria-label={t('ui.next')} onClick={() => setPage(page + 1)} />
                </div>
            )}
            {editing && <Modal open title={t('channels.test_channel.modify')} onClose={() => setEditing(null)} footer={<>
                <Button onClick={() => setEditing(null)}>{t('ui.cancel')}</Button>
                <Button variant="primary" icon="save" loading={busy === editing.id} onClick={() => call(editing.id, `/test-channel/bookings/${editing.id}/modify`, dates)}>{t('ui.save')}</Button>
            </>}>
                <div className="form-grid">
                    <Input fieldClass="span-6" type="date" label={t('channels.test_channel.check_in')} value={dates.check_in} onChange={(e) => setDates({ ...dates, check_in: e.target.value })} />
                    <Input fieldClass="span-6" type="date" label={t('channels.test_channel.check_out')} value={dates.check_out} onChange={(e) => setDates({ ...dates, check_out: e.target.value })} />
                </div>
            </Modal>}
        </Card>
    );
}
