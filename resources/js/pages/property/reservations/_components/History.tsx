import { useCallback, useEffect, useState } from 'react';
import { Button, EmptyState, Icon, Textarea, toast } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t, tOr } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import type { HistoryData } from './types';

/** Notes, history and documents of a reservation, loaded on demand (tab opened). */
export function useHistory(id: string) {
    const [data, setData] = useState<HistoryData | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const load = useCallback(() => {
        setError(null);
        http.get<HistoryData>(propertyApiUrl(`/reservations/${id}/history`)).then(setData).catch((e: ApiError) => setError(e));
    }, [id]);
    useEffect(() => { load(); }, [load]);
    return { data, error, load, setData };
}

type H = ReturnType<typeof useHistory>;

function Loading({ h }: { h: H }) {
    if (h.error) return <EmptyState icon="circle-alert" title={h.error.message} action={<Button icon="refresh" onClick={h.load}>{t('reservations.retry')}</Button>} />;
    return <div className="stack"><div className="skeleton" style={{ height: 44 }} /><div className="skeleton" style={{ height: 44 }} /></div>;
}

export function NotesBox({ h, id, canAdd }: { h: H; id: string; canAdd: boolean }) {
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    if (!h.data) return <Loading h={h} />;
    const add = async () => {
        if (!body.trim() || busy) return;
        setBusy(true);
        setError(undefined);
        try {
            const res = await http.post<{ message: string; note: HistoryData['notes'][number] }>(propertyApiUrl(`/reservations/${id}/notes`), { body });
            toast.success(res.message);
            h.setData({ ...h.data!, notes: [res.note, ...h.data!.notes] });
            setBody('');
        } catch (e) {
            const err = e as ApiError;
            setError(err.field('body') ?? err.message);
            if (!err.field('body')) toast.error(err.message, err.ref);
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="stack">
            {canAdd && <form className="stack" onSubmit={(e) => { e.preventDefault(); add(); }}>
                <Textarea label={t('reservations.detail.add_note')} rows={2} maxLength={2000} value={body} placeholder={t('reservations.detail.note_placeholder')}
                    onChange={(e) => setBody(e.target.value)} error={error}
                    onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) add(); }} />
                <div><Button type="submit" variant="primary" size="sm" icon="plus" loading={busy} disabled={!body.trim()}>{t('reservations.detail.add_note')}</Button></div>
            </form>}
            {h.data.notes.length === 0 ? <p className="muted">{t('reservations.detail.no_notes')}</p> : (
                <ul className="list-plain">
                    {h.data.notes.map((n) => (
                        <li key={n.id} className="activity">
                            <span className="a-icon tone-amber"><Icon name="sticky-note" size={16} /></span>
                            <div className="grow"><div className="a-title note-body">{n.body}</div><div className="a-sub">{n.user ?? '—'}</div></div>
                            <span className="a-time">{dateTime(n.at)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export function HistoryList({ h }: { h: H }) {
    if (!h.data) return <Loading h={h} />;
    if (h.data.events.length === 0) return <p className="muted">{t('reservations.detail.no_history')}</p>;
    return (
        <ul className="list-plain">
            {h.data.events.map((e, i) => (
                <li key={i} className="activity">
                    <span className={`a-icon tone-${e.type === 'status' ? 'blue' : 'slate'}`}><Icon name={e.type === 'status' ? 'repeat' : 'history'} size={16} /></span>
                    <div className="grow">
                        <div className="a-title">
                            {e.type === 'status'
                                ? <>{e.from ? t('reservations.detail.status_change', { from: t(`reservations.status.${e.from}`), to: t(`reservations.status.${e.to}`) }) : t('reservations.detail.status_set', { to: t(`reservations.status.${e.to}`) })}
                                    {e.room ? ` · ${t('reservations.detail.room_label', { n: e.room })}` : ''}</>
                                : tOr(`reservations.history_actions.${e.action}`, e.action)}
                        </div>
                        <div className="a-sub">{e.user ?? '—'}{e.type === 'status' && e.note ? ` · ${e.note}` : ''}{e.type === 'change' && e.keys.length > 0 ? ` · ${e.keys.join(', ')}` : ''}</div>
                    </div>
                    <span className="a-time">{dateTime(e.at)}</span>
                </li>
            ))}
        </ul>
    );
}

export function DocumentsList({ h }: { h: H }) {
    if (!h.data) return <Loading h={h} />;
    if (h.data.documents.length === 0) return <p className="muted">{t('reservations.detail.no_documents')}</p>;
    return (
        <ul className="list-plain">
            {h.data.documents.map((d) => (
                <li key={d.id} className="activity">
                    <span className="a-icon tone-slate"><Icon name="file-text" size={16} /></span>
                    <div className="grow"><a className="a-title" href={propertyApiUrl(`/guests/${d.guest_id}/documents/${d.id}`)}>{d.name}</a><div className="a-sub">{t(`guests.document_types.${d.type}`)} · {Math.max(1, Math.round(d.size / 1024))} KB</div></div>
                    <span className="a-time">{dateTime(d.at)}</span>
                </li>
            ))}
        </ul>
    );
}
