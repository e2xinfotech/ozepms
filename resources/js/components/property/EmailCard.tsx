import { useEffect, useState } from 'react';
import { Alert, Badge, Button, Card, Input, Select, Toggle, toast } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

export interface EmailSettings {
    host: string | null; port: number | null; encryption: string; username: string | null; has_password: boolean;
    from_address: string | null; from_name: string | null; reply_to: string | null;
    events: Record<string, boolean>; send_for_channels: boolean; source: 'property' | 'platform' | 'env'; sends: boolean;
}
interface LogRow { id: string; event: string; to: string; subject: string; status: string; error: string | null; at: string | null }

const EVENTS = ['booking_confirmation', 'booking_modification', 'booking_cancellation', 'check_out_thanks'];
const TONES: Record<string, 'green' | 'red' | 'amber'> = { sent: 'green', failed: 'red', queued: 'amber' };

function toForm(s: EmailSettings) {
    return { host: s.host ?? '', port: s.port ? String(s.port) : '', encryption: s.encryption, username: s.username ?? '', password: '', clear_password: false,
        from_address: s.from_address ?? '', from_name: s.from_name ?? '', reply_to: s.reply_to ?? '', events: s.events, send_for_channels: s.send_for_channels };
}

/**
 * E-mail settings: the SMTP account that sends the e-mail (a hotel's own, or the platform default), which e-mails
 * go to guests, a test button and, for a hotel, the log of what was sent.
 */
export function EmailCard({ initial, disabled, url, scope }: { initial: EmailSettings; disabled: boolean; url: string; scope: 'property' | 'platform' }) {
    const [state, setState] = useState(initial);
    const [form, setForm] = useState(toForm(initial));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [to, setTo] = useState('');
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<{ ok: boolean; message: string; error?: string } | null>(null);
    const [logs, setLogs] = useState<LogRow[] | null>(null);
    const isProperty = scope === 'property';
    const dirty = JSON.stringify(form) !== JSON.stringify(toForm(state));

    const loadLogs = async () => {
        if (!isProperty) return;
        try { setLogs((await http.get<{ data: LogRow[] }>(`${url}/logs`)).data); } catch { setLogs([]); }
    };
    useEffect(() => { void loadLogs(); }, []);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.put<{ message: string; email: EmailSettings }>(url, {
                host: form.host.trim() || null, port: form.port ? Number(form.port) : null, encryption: form.encryption, username: form.username.trim() || null,
                password: form.password || null, clear_password: form.clear_password, from_address: form.from_address.trim() || null,
                from_name: form.from_name.trim() || null, reply_to: form.reply_to.trim() || null,
                ...(isProperty ? { events: form.events, send_for_channels: form.send_for_channels } : {}),
            });
            setState(res.email);
            setForm(toForm(res.email));
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };
    const test = async () => {
        setTesting(true);
        setResult(null);
        try {
            setResult(await http.post<{ ok: boolean; message: string; error?: string }>(`${url}/test`, { to }));
        } catch (e) {
            setResult({ ok: false, message: (e as ApiError).message });
        } finally {
            setTesting(false);
        }
    };
    const resend = async (id: string) => {
        try {
            const res = await http.post<{ message: string }>(`${url}/logs/${id}/resend`);
            toast.success(res.message);
            await loadLogs();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        }
    };
    const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch });

    return (
        <Card title={t(isProperty ? 'mailsettings.title' : 'mailsettings.platform_title')}>
            <div className="form-grid">
                <p className="span-12 muted text-sm">{t(isProperty ? 'mailsettings.description' : 'mailsettings.platform_description')}</p>
                <div className="span-12">
                    <Alert tone={state.sends ? 'success' : 'warn'}>
                        {state.sends ? t(`mailsettings.source.${state.source}`) : t('mailsettings.not_sending')}
                    </Alert>
                </div>
                <Input fieldClass="span-6" label={t('mailsettings.host')} optional disabled={disabled} value={form.host} placeholder="smtp.example.com"
                    onChange={(e) => set({ host: e.target.value })} error={error?.field('host')} />
                <Input fieldClass="span-3" label={t('mailsettings.port')} optional type="number" disabled={disabled} value={form.port} placeholder="587"
                    onChange={(e) => set({ port: e.target.value })} error={error?.field('port')} />
                <Select fieldClass="span-3" label={t('mailsettings.encryption')} disabled={disabled} value={form.encryption} onChange={(e) => set({ encryption: e.target.value })}
                    options={['tls', 'ssl', 'none'].map((v) => ({ value: v, label: t(`mailsettings.encryptions.${v}`) }))} />
                <Input fieldClass="span-6" label={t('mailsettings.username')} optional autoComplete="off" disabled={disabled} value={form.username}
                    onChange={(e) => set({ username: e.target.value })} error={error?.field('username')} />
                <Input fieldClass="span-6" label={t('mailsettings.password')} optional type="password" autoComplete="new-password" disabled={disabled} value={form.password}
                    placeholder={state.has_password ? '••••••••' : ''} hint={state.has_password ? t('mailsettings.password_kept') : undefined}
                    onChange={(e) => set({ password: e.target.value, clear_password: false })} error={error?.field('password')} />
                <Input fieldClass="span-4" label={t('mailsettings.from_address')} optional type="email" disabled={disabled} value={form.from_address} placeholder="no-reply@hotel.com"
                    onChange={(e) => set({ from_address: e.target.value })} error={error?.field('from_address')} />
                <Input fieldClass="span-4" label={t('mailsettings.from_name')} optional disabled={disabled} value={form.from_name}
                    onChange={(e) => set({ from_name: e.target.value })} error={error?.field('from_name')} />
                <Input fieldClass="span-4" label={t('mailsettings.reply_to')} optional type="email" disabled={disabled} value={form.reply_to}
                    onChange={(e) => set({ reply_to: e.target.value })} hint={t('mailsettings.reply_to_hint')} error={error?.field('reply_to')} />
                {isProperty && (
                    <div className="span-12">
                        <strong className="text-sm">{t('mailsettings.events_title')}</strong>
                        <div className="stack" style={{ marginTop: 8, gap: 8 }}>
                            {EVENTS.map((e) => <Toggle key={e} disabled={disabled} checked={form.events[e] ?? true} onChange={(v) => set({ events: { ...form.events, [e]: v } })} label={t(`mailsettings.events.${e}`)} />)}
                            <Toggle disabled={disabled} checked={form.send_for_channels} onChange={(v) => set({ send_for_channels: v })} label={t('mailsettings.for_channels')} />
                        </div>
                    </div>
                )}
                {!disabled && (
                    <div className="span-12 row" style={{ justifyContent: 'flex-end' }}>
                        <Button variant="primary" icon="save" loading={busy} disabled={!dirty} onClick={save}>{t('ui.save_changes')}</Button>
                    </div>
                )}
                {!disabled && (
                    <div className="span-12">
                        <strong className="text-sm">{t('mailsettings.test_title')}</strong>
                        <div className="row" style={{ gap: 8, marginTop: 8 }}>
                            <input className="control grow" type="email" placeholder={t('mailsettings.test_placeholder')} value={to} onChange={(e) => setTo(e.target.value)} aria-label={t('mailsettings.test_title')} />
                            <Button variant="outline" icon="send" loading={testing} disabled={!to || dirty} onClick={test}>{t('mailsettings.test_send')}</Button>
                        </div>
                        {dirty && <div className="field-hint">{t('mailsettings.test_save_first')}</div>}
                        {result && <div style={{ marginTop: 8 }}><Alert tone={result.ok ? 'success' : 'danger'}>{result.message}{result.error ? ` — ${result.error}` : ''}</Alert></div>}
                    </div>
                )}
                {isProperty && (
                    <div className="span-12">
                        <strong className="text-sm">{t('mailsettings.log_title')}</strong>
                        {logs && logs.length === 0 && <p className="muted text-sm" style={{ marginTop: 8 }}>{t('mailsettings.log_empty')}</p>}
                        {logs && logs.length > 0 && (
                            <div className="table-wrap table-scroll" style={{ marginTop: 8 }}>
                                <table className="table">
                                    <thead><tr><th>{t('mailsettings.log_when')}</th><th>{t('mailsettings.log_to')}</th><th>{t('mailsettings.log_subject')}</th><th>{t('mailsettings.log_status')}</th><th /></tr></thead>
                                    <tbody>
                                        {logs.map((l) => (
                                            <tr key={l.id}>
                                                <td className="nowrap">{dateTime(l.at)}</td>
                                                <td>{l.to}</td>
                                                <td><div className="ellipsis" style={{ maxWidth: 320 }} title={l.subject}>{l.subject}</div>{l.error && <div className="cell-sub" style={{ color: 'var(--red-fg)' }}>{l.error}</div>}</td>
                                                <td><Badge tone={TONES[l.status] ?? 'amber'}>{t(`mailsettings.status.${l.status}`)}</Badge></td>
                                                <td>{l.status === 'failed' && !disabled && l.event !== 'system' && <Button size="sm" variant="outline" icon="rotate-cw" onClick={() => resend(l.id)}>{t('mailsettings.resend')}</Button>}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}
