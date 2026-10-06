import { useEffect, useState } from 'react';
import { Alert, Button, Card, Checkbox, ConfirmDialog, Input, toast } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

interface ApiKeyRow { id: string; name: string; prefix: string; abilities: string[]; last_used_at: string | null; created_at: string | null }

const ABILITIES = ['availability', 'reservations.create', 'reservations.read'];

/** Settings → API keys for the versioned API: create (the key is shown once), list and revoke. */
export function ApiKeysCard({ disabled }: { disabled: boolean }) {
    const [keys, setKeys] = useState<ApiKeyRow[] | null>(null);
    const [form, setForm] = useState({ name: '', abilities: ['availability'] as string[] });
    const [plain, setPlain] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [revoke, setRevoke] = useState<ApiKeyRow | null>(null);

    useEffect(() => {
        http.get<{ keys: ApiKeyRow[] }>(propertyApiUrl('/settings/api-keys')).then((r) => setKeys(r.keys)).catch(() => setKeys([]));
    }, []);

    const toggle = (a: string, on: boolean) => setForm((f) => ({ ...f, abilities: on ? [...f.abilities, a] : f.abilities.filter((x) => x !== a) }));

    const create = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string; key: ApiKeyRow; plain: string }>(propertyApiUrl('/settings/api-keys'), { name: form.name.trim(), abilities: form.abilities });
            setKeys((k) => [res.key, ...(k ?? [])]);
            setPlain(res.plain);
            setForm({ name: '', abilities: ['availability'] });
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };

    const doRevoke = async () => {
        if (!revoke) return;
        setBusy(true);
        try {
            const res = await http.delete<{ message: string }>(propertyApiUrl(`/settings/api-keys/${revoke.id}`));
            setKeys((k) => (k ?? []).filter((x) => x.id !== revoke.id));
            toast.success(res.message);
            setRevoke(null);
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        if (!plain) return;
        try { await navigator.clipboard.writeText(plain); toast.success(t('property.api_keys.copied')); } catch { /* clipboard blocked: the key stays selectable */ }
    };

    return (
        <Card title={t('property.api_keys.title')}>
            <div className="stack">
                <p className="muted text-sm">{t('property.api_keys.description')}</p>
                {plain && <div className="stack">
                    <Alert tone="warn">{t('property.api_keys.once')}</Alert>
                    <div className="row" style={{ gap: 8 }}>
                        <input className="control grow mono" readOnly value={plain} onFocus={(e) => e.target.select()} aria-label={t('property.api_keys.title')} />
                        <Button variant="outline" icon="copy" onClick={copy}>{t('property.api_keys.copy')}</Button>
                    </div>
                </div>}

                {keys === null ? <div className="skeleton" style={{ height: 60 }} /> : keys.length === 0 ? <p className="muted text-sm">{t('property.api_keys.none')}</p> : (
                    <div className="table-wrap table-scroll">
                        <table className="table">
                            <thead><tr><th>{t('property.api_keys.name')}</th><th>{t('property.api_keys.abilities')}</th><th>{t('property.api_keys.last_used')}</th>{!disabled && <th />}</tr></thead>
                            <tbody>
                                {keys.map((k) => <tr key={k.id}>
                                    <td><strong>{k.name}</strong><div className="muted text-xs mono">{k.prefix}</div></td>
                                    <td className="text-sm">{k.abilities.map((a) => t(`property.api_keys.ability.${a.replace('.', '_')}`)).join(', ')}</td>
                                    <td className="text-sm">{k.last_used_at ? dateTime(k.last_used_at) : t('property.api_keys.never')}</td>
                                    {!disabled && <td className="right"><Button variant="ghost" size="sm" icon="trash" onClick={() => setRevoke(k)}>{t('property.api_keys.revoke')}</Button></td>}
                                </tr>)}
                            </tbody>
                        </table>
                    </div>
                )}

                {!disabled && <div className="form-grid">
                    <Input fieldClass="span-6" label={t('property.api_keys.name')} maxLength={80} value={form.name} hint={t('property.api_keys.name_hint')}
                        onChange={(e) => setForm({ ...form, name: e.target.value })} error={error?.field('name')} />
                    <div className="field span-6">
                        <span className="field-label">{t('property.api_keys.abilities')}</span>
                        {ABILITIES.map((a) => <Checkbox key={a} checked={form.abilities.includes(a)} onChange={(e) => toggle(a, e.target.checked)} label={t(`property.api_keys.ability.${a.replace('.', '_')}`)} />)}
                        {error?.field('abilities') && <div className="field-error">{error.field('abilities')}</div>}
                    </div>
                    <p className="span-12 muted text-xs">{t('property.api_keys.docs')}</p>
                    <div className="span-12 row" style={{ justifyContent: 'flex-end' }}>
                        <Button variant="primary" icon="key" loading={busy} disabled={!form.name.trim() || form.abilities.length === 0} onClick={create}>{t('property.api_keys.create')}</Button>
                    </div>
                </div>}
            </div>
            <ConfirmDialog open={revoke !== null} danger busy={busy} title={t('property.api_keys.revoke')} message={t('property.api_keys.revoke_confirm')}
                confirmLabel={t('property.api_keys.revoke')} onConfirm={doRevoke} onClose={() => setRevoke(null)} />
        </Card>
    );
}
