import { useState } from 'react';
import { Button, Card, Input, KeyValue } from '@/components/ui';
import { dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { toast } from '@/components/ui';
import { act, fieldError } from '../../_accommodation/shared';
import type { ConnectionDetail, ConnectionRow } from './types';

export function OverviewTab({ c, onSaved }: { c: ConnectionDetail; onSaved: (row: ConnectionRow) => void }) {
    const [form, setForm] = useState({ name: c.name ?? '', external_hotel_id: c.hotel_id, credentials: Object.fromEntries(c.credential_fields.map((f) => [f.key, f.value ?? ''])) as Record<string, string> });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const [show, setShow] = useState(false);

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.put<{ message: string; connection: ConnectionRow }>(propertyApiUrl(`/channels/${c.id}`), form), setError);
        setBusy(false);
        if (res) onSaved(res.connection);
    };
    const copy = async (text: string) => {
        try { await navigator.clipboard.writeText(text); toast.success(t('channels.messages.copied')); } catch { /* clipboard blocked: the field stays selectable */ }
    };

    return (
        <div className="ch-two">
            <Card title={t('channels.edit')}>
                <div className="form-grid">
                    <Input fieldClass="span-12" label={t('channels.name')} optional maxLength={80} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <Input fieldClass="span-12" label={t('channels.hotel_id')} required disabled={!c.can_edit_credentials} maxLength={64} value={form.external_hotel_id} onChange={(e) => setForm({ ...form, external_hotel_id: e.target.value })} error={fieldError(error, 'external_hotel_id')} />
                    {!c.can_edit_credentials && <div className="span-12"><p className="muted text-sm">{t('channels.approval.credentials_by_e2x')}</p></div>}
                    {c.credential_fields.map((f) => (
                        <Input key={f.key} fieldClass="span-12" label={t(`channels.fields.${f.key}`)} required={f.required && !f.has_value} type={f.type === 'secret' ? 'password' : 'text'} autoComplete="off"
                            hint={f.type === 'secret' && f.has_value ? t('channels.secret_kept') : undefined}
                            value={form.credentials[f.key] ?? ''} onChange={(e) => setForm({ ...form, credentials: { ...form.credentials, [f.key]: e.target.value } })} error={fieldError(error, `credentials.${f.key}`)} />
                    ))}
                    <div className="span-12"><Button variant="primary" icon="save" loading={busy} onClick={save}>{t('channels.save')}</Button></div>
                </div>
            </Card>
            <div className="stack" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                <Card title={t('channels.overview.health')}>
                    <KeyValue items={[
                        { label: t('channels.col.status'), value: t(`channels.status.${c.status}`) },
                        { label: t('channels.col.last_success'), value: dateTime(c.last_success_at) },
                        { label: t('channels.kpi.failures'), value: <span className="num">{c.failures}</span> },
                        { label: t('channels.kpi.pending'), value: c.waiting ? t('channels.overview.waiting') : '—' },
                        { label: t('channels.actions.full_sync'), value: dateTime(c.last_full_sync_at) },
                    ]} />
                </Card>
                <Card title={t('channels.overview.webhook')}>
                    <p className="muted text-sm">{t('channels.overview.webhook_text')}</p>
                    <div className="form-grid">
                        <div className="field span-12"><span className="field-label">{t('channels.overview.webhook_url')}</span>
                            <div className="ch-copy"><input className="input" readOnly value={c.webhook_url} onFocus={(e) => e.target.select()} /><Button size="sm" icon="copy" onClick={() => copy(c.webhook_url)}>{t('channels.actions.copy')}</Button></div></div>
                        <div className="field span-12"><span className="field-label">{t('channels.overview.webhook_secret')}</span>
                            <div className="ch-copy"><input className="input" readOnly type={show ? 'text' : 'password'} value={c.webhook_secret ?? ''} onFocus={(e) => e.target.select()} />
                                <Button size="sm" icon={show ? 'eye-off' : 'eye'} onClick={() => setShow(!show)}>{show ? t('channels.actions.hide') : t('channels.actions.show')}</Button>
                                <Button size="sm" icon="copy" onClick={() => copy(c.webhook_secret ?? '')}>{t('channels.actions.copy')}</Button></div></div>
                    </div>
                </Card>
                <Card title={t('channels.overview.how')}>
                    <ol className="ch-steps">{[0, 1, 2, 3].map((i) => <li key={i}>{t(`channels.overview.how_items.${i}`, { days: c.sync_days })}</li>)}</ol>
                </Card>
            </div>
        </div>
    );
}
