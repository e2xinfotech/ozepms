import { useEffect, useRef, useState } from 'react';
import { Alert, Badge, Button, Card, Input, Modal, Textarea, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

interface Template { event: string; custom: boolean; subject: string; body: string; default_subject: string; default_body: string }
interface Listing { data: Template[]; placeholders: string[] }

/** Settings → the hotel's own wording for each e-mail sent to guests (pre-arrival, confirmation, receipt, invoice …). */
export function EmailTemplatesCard({ enabled, disabled }: { enabled: Record<string, boolean>; disabled: boolean }) {
    const [list, setList] = useState<Listing | null>(null);
    const [editing, setEditing] = useState<Template | null>(null);
    const url = propertyApiUrl('/settings/email/templates');
    const load = () => http.get<Listing>(url).then(setList).catch(() => setList({ data: [], placeholders: [] }));
    useEffect(() => { void load(); }, []);

    return (
        <Card title={t('mailsettings.templates_title')}>
            <p className="muted text-sm" style={{ marginBottom: 12 }}>{t('mailsettings.templates_description')}</p>
            {!list ? <div className="skeleton" style={{ height: 120 }} /> : (
                <div className="table-wrap table-scroll">
                    <table className="table">
                        <tbody>
                            {list.data.map((row) => (
                                <tr key={row.event}>
                                    <td>
                                        <div className="cell-main">{t(`mailsettings.events.${row.event}`)}</div>
                                        <div className="cell-sub ellipsis" style={{ maxWidth: 460 }}>{row.subject}</div>
                                    </td>
                                    <td className="nowrap">
                                        <Badge size="sm" tone={row.custom ? 'blue' : 'slate'}>{row.custom ? t('mailsettings.template_custom') : t('mailsettings.template_default')}</Badge>
                                        {enabled[row.event] === false && <div className="cell-sub">{t('mailsettings.template_off')}</div>}
                                    </td>
                                    <td className="col-actions"><Button size="sm" variant="outline" icon="pencil" onClick={() => setEditing(row)}>{disabled ? t('mailsettings.template_preview') : t('mailsettings.template_edit')}</Button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {editing && list && <TemplateDialog row={editing} placeholders={list.placeholders} readOnly={disabled} url={url} onClose={() => setEditing(null)} onChanged={() => { setEditing(null); void load(); }} />}
        </Card>
    );
}

function TemplateDialog({ row, placeholders, readOnly, url, onClose, onChanged }: { row: Template; placeholders: string[]; readOnly: boolean; url: string; onClose: () => void; onChanged: () => void }) {
    const [subject, setSubject] = useState(row.subject);
    const [body, setBody] = useState(row.body);
    const [busy, setBusy] = useState<'save' | 'reset' | 'preview' | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [preview, setPreview] = useState<{ subject: string; html: string } | null>(null);
    const last = useRef<'subject' | 'body'>('body');
    const eventUrl = `${url}/${row.event}`;

    const insert = (word: string) => {
        const token = `{${word}}`;
        const field = document.getElementById(last.current === 'subject' ? 'tpl-subject' : 'tpl-body') as HTMLInputElement | HTMLTextAreaElement | null;
        const value = last.current === 'subject' ? subject : body;
        const set = last.current === 'subject' ? setSubject : setBody;
        const start = field?.selectionStart ?? value.length;
        const end = field?.selectionEnd ?? value.length;
        set(value.slice(0, start) + token + value.slice(end));
        requestAnimationFrame(() => { field?.focus(); field?.setSelectionRange(start + token.length, start + token.length); });
    };
    const run = async (kind: 'save' | 'reset' | 'preview') => {
        setBusy(kind);
        setError(null);
        try {
            if (kind === 'preview') {
                setPreview(await http.post<{ subject: string; html: string }>(`${eventUrl}/preview`, { subject, body }));
            } else if (kind === 'save') {
                const res = await http.put<{ message: string }>(eventUrl, { subject, body });
                toast.success(res.message);
                onChanged();
            } else {
                const res = await http.delete<{ message: string }>(eventUrl);
                toast.success(res.message);
                onChanged();
            }
        } catch (e) {
            setError(e as ApiError);
        } finally {
            setBusy(null);
        }
    };

    return (
        <Modal open size="lg" onClose={onClose} title={t(`mailsettings.events.${row.event}`)} footer={<>
            {!readOnly && row.custom && <Button variant="outline" loading={busy === 'reset'} onClick={() => run('reset')}>{t('mailsettings.template_reset')}</Button>}
            <span className="spacer" />
            <Button onClick={onClose}>{t('mailsettings.template_close')}</Button>
            <Button variant="outline" icon="eye" loading={busy === 'preview'} onClick={() => run('preview')}>{t('mailsettings.template_preview')}</Button>
            {!readOnly && <Button variant="primary" icon="save" loading={busy === 'save'} onClick={() => run('save')}>{t('ui.save_changes')}</Button>}
        </>}>
            <div className="stack">
                {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
                <Input id="tpl-subject" label={t('mailsettings.template_subject')} required maxLength={150} disabled={readOnly} value={subject}
                    onFocus={() => { last.current = 'subject'; }} onChange={(e) => setSubject(e.target.value)} error={error?.field('subject')} />
                <Textarea id="tpl-body" label={t('mailsettings.template_body')} required rows={7} maxLength={3000} disabled={readOnly} value={body}
                    onFocus={() => { last.current = 'body'; }} onChange={(e) => setBody(e.target.value)} error={error?.field('body')} />
                {!readOnly && (
                    <div>
                        <div className="field-hint" style={{ marginBottom: 6 }}>{t('mailsettings.template_placeholders')}</div>
                        <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
                            {placeholders.map((p) => <button key={p} type="button" className="btn btn-outline btn-sm" onClick={() => insert(p)} title={`{${p}}`}>{t(`mailsettings.placeholders.${p}`)}</button>)}
                        </div>
                    </div>
                )}
                {preview && (
                    <div>
                        <strong className="text-sm">{t('mailsettings.template_preview_title')}</strong>
                        <div className="muted text-sm" style={{ margin: '4px 0 8px' }}>{preview.subject}</div>
                        <iframe title={t('mailsettings.template_preview_title')} sandbox="" srcDoc={preview.html} style={{ width: '100%', height: 460, border: '1px solid var(--line)', borderRadius: 10, background: '#fff' }} />
                    </div>
                )}
            </div>
        </Modal>
    );
}
