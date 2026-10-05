import QRCode from 'qrcode';
import { useEffect, useRef, useState } from 'react';
import { Alert, Badge, Button, Card, FormSection, Input, KeyValue, Modal, PageHeader, toast } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Props {
    two_factor_enabled: boolean;
    two_factor_required: boolean;
    recovery_codes_left: number;
    password_changed_at: string | null;
    last_login_at: string | null;
    recent_logins: { ip: string | null; agent: string | null; ok: boolean; reason: string | null; at: string | null }[];
    password_rules: { min: number };
}

function QrCanvas({ text }: { text: string }) {
    const ref = useRef<HTMLCanvasElement>(null);
    useEffect(() => {
        if (ref.current) QRCode.toCanvas(ref.current, text, { width: 200, margin: 1 }).catch(() => undefined);
    }, [text]);
    return <canvas ref={ref} width={200} height={200} aria-label={t('auth.two_factor_qr')} />;
}

function browserOf(agent: string | null): string {
    if (!agent) return '—';
    const browser = /Edg\//.test(agent) ? 'Edge' : /Chrome\//.test(agent) ? 'Chrome' : /Firefox\//.test(agent) ? 'Firefox' : /Safari\//.test(agent) ? 'Safari' : agent.split(' ')[0];
    const os = /Windows/.test(agent) ? 'Windows' : /Mac OS X/.test(agent) ? 'macOS' : /Android/.test(agent) ? 'Android' : /iPhone|iPad/.test(agent) ? 'iOS' : /Linux/.test(agent) ? 'Linux' : '';
    return os ? `${browser} · ${os}` : browser;
}

/** Password change, two-step verification and recent sign-ins of the signed-in user. */
function SecurityPage(p: Props) {
    const [setup, setSetup] = useState<{ secret: string; uri: string } | null>(null);
    const [code, setCode] = useState('');
    const [codes, setCodes] = useState<string[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [tfError, setTfError] = useState<ApiError | null>(null);
    const [disableOpen, setDisableOpen] = useState(false);
    const [disablePassword, setDisablePassword] = useState('');
    const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [pwBusy, setPwBusy] = useState(false);
    const [pwError, setPwError] = useState<ApiError | null>(null);

    const begin = async () => {
        setBusy(true);
        setTfError(null);
        try {
            setSetup(await http.post<{ secret: string; uri: string }>('/web-api/account/two-factor/begin'));
        } catch (e) {
            setTfError(e as ApiError);
        } finally {
            setBusy(false);
        }
    };

    const confirm = async () => {
        setBusy(true);
        setTfError(null);
        try {
            const res = await http.post<{ recovery_codes: string[]; message: string }>('/web-api/account/two-factor/confirm', { code: code.trim() });
            setCodes(res.recovery_codes);
            setSetup(null);
            toast.success(res.message);
        } catch (e) {
            setTfError(e as ApiError);
        } finally {
            setBusy(false);
        }
    };

    const disable = async () => {
        setBusy(true);
        setTfError(null);
        try {
            const res = await http.post<{ message: string }>('/web-api/account/two-factor/disable', { current_password: disablePassword });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            setTfError(e as ApiError);
            setBusy(false);
        }
    };

    const changePassword = async () => {
        setPwBusy(true);
        setPwError(null);
        try {
            const res = await http.put<{ message: string }>('/web-api/account/password', pw);
            toast.success(res.message);
            setPw({ current_password: '', password: '', password_confirmation: '' });
        } catch (e) {
            setPwError(e as ApiError);
        } finally {
            setPwBusy(false);
        }
    };

    const enabled = p.two_factor_enabled || codes !== null;

    return (
        <div className="content" style={{ maxWidth: 1040 }}>
            <PageHeader title={t('auth.security_title')} description={t('auth.security_sub')} />
            {p.two_factor_required && !enabled && <Alert tone="warn">{t('auth.two_factor_required')}</Alert>}

            <Card title={t('auth.two_factor_section')} actions={<Badge status={enabled ? 'enabled' : 'disabled'} />}>
                <div className="stack">
                    {tfError && !tfError.fields.code && !tfError.fields.current_password && <Alert tone="danger">{tfError.message}</Alert>}
                    {codes ? (
                        <>
                            <Alert tone="success">{t('auth.two_factor_enabled')}</Alert>
                            <div className="strong">{t('auth.recovery_codes')}</div>
                            <p className="muted text-sm">{t('auth.recovery_codes_hint')}</p>
                            <div className="code-box recovery-grid">{codes.map((c) => <span key={c} className="num">{c}</span>)}</div>
                            <div className="row">
                                <Button icon="copy" onClick={() => navigator.clipboard?.writeText(codes.join('\n')).then(() => toast.success(t('ui.copied')))}>{t('ui.copy')}</Button>
                                <Button variant="primary" onClick={() => window.location.reload()}>{t('auth.codes_saved')}</Button>
                            </div>
                        </>
                    ) : setup ? (
                        <div className="qr-box">
                            <QrCanvas text={setup.uri} />
                            <div className="stack grow" style={{ maxWidth: 460 }}>
                                <p>{t('auth.two_factor_scan')}</p>
                                <div className="text-sm muted">{t('auth.two_factor_manual')}</div>
                                <div className="code-box num" style={{ letterSpacing: '.08em', wordBreak: 'break-all' }}>{setup.secret}</div>
                                <Input label={t('auth.two_factor_code')} icon="shield-check" autoComplete="one-time-code" inputMode="numeric" maxLength={6} autoFocus
                                    value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} error={tfError?.field('code')} />
                                <div className="row">
                                    <Button onClick={() => { setSetup(null); setCode(''); }}>{t('ui.cancel')}</Button>
                                    <Button variant="primary" icon="check" loading={busy} disabled={code.length !== 6} onClick={confirm}>{t('auth.two_factor_confirm')}</Button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="row-between">
                            <div>
                                <div className="strong">{enabled ? t('auth.two_factor_on') : t('auth.two_factor_off')}</div>
                                <div className="muted text-sm">
                                    {enabled ? t('auth.recovery_left', { n: p.recovery_codes_left }) : t('auth.two_factor_why')}
                                    {p.two_factor_required && <> · {t('auth.two_factor_mandatory')}</>}
                                </div>
                            </div>
                            {enabled
                                ? !p.two_factor_required && <Button variant="danger-soft" icon="shield" onClick={() => { setDisableOpen(true); setTfError(null); }}>{t('auth.two_factor_disable')}</Button>
                                : <Button variant="primary" icon="qr-code" loading={busy} onClick={begin}>{t('auth.two_factor_setup')}</Button>}
                        </div>
                    )}
                </div>
            </Card>

            <FormSection title={t('auth.change_password')} description={t('auth.reset_sub', { min: p.password_rules.min })}
                actions={<Button variant="primary" icon="save" loading={pwBusy} onClick={changePassword}>{t('auth.change_password')}</Button>}>
                <Input fieldClass="span-4" label={t('auth.current_password')} type="password" autoComplete="current-password" required value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} error={pwError?.field('current_password')} />
                <Input fieldClass="span-4" label={t('auth.new_password')} type="password" autoComplete="new-password" required value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} error={pwError?.field('password')} />
                <Input fieldClass="span-4" label={t('auth.confirm_password')} type="password" autoComplete="new-password" required value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} error={pwError?.field('password_confirmation')} />
            </FormSection>

            <Card title={t('auth.recent_activity')}>
                <KeyValue items={[
                    { icon: 'log-in', label: t('auth.last_login'), value: p.last_login_at ? dateTime(p.last_login_at) : t('ui.never') },
                    { icon: 'key', label: t('auth.password_changed'), value: p.password_changed_at ? dateTime(p.password_changed_at) : '—' },
                ]} />
                <div className="table-scroll" style={{ marginTop: 16 }}>
                    <table className="table">
                        <thead><tr><th>{t('admin.audit_cols.time')}</th><th>{t('ui.status_label')}</th><th>{t('admin.audit_cols.ip')}</th><th>{t('auth.device')}</th></tr></thead>
                        <tbody>
                            {p.recent_logins.map((l, i) => (
                                <tr key={i}>
                                    <td className="num">{dateTime(l.at)}</td>
                                    <td><Badge size="sm" tone={l.ok ? 'green' : 'red'}>{l.ok ? t('auth.signin_ok') : t('auth.signin_failed')}</Badge></td>
                                    <td className="num">{l.ip ?? '—'}</td>
                                    <td title={l.agent ?? ''}>{browserOf(l.agent)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <Modal open={disableOpen} title={t('auth.two_factor_disable')} onClose={() => setDisableOpen(false)} footer={<>
                <Button onClick={() => setDisableOpen(false)}>{t('ui.cancel')}</Button>
                <Button variant="danger" loading={busy} onClick={disable}>{t('auth.two_factor_disable')}</Button>
            </>}>
                <div className="stack">
                    <p>{t('auth.two_factor_disable_confirm')}</p>
                    <Input label={t('auth.current_password')} type="password" autoComplete="current-password" autoFocus value={disablePassword}
                        onChange={(e) => setDisablePassword(e.target.value)} error={tfError?.field('current_password')} />
                </div>
            </Modal>
        </div>
    );
}

createPage(SecurityPage);
