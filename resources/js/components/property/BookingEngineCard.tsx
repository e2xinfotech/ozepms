import { useState } from 'react';
import { Alert, Button, Card, Input, Textarea, Toggle, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

export interface BookingEngineSettings { enabled: boolean; intro: string | null; terms: string | null; open: boolean; reason: string | null; url: string }

/** Settings → the hotel's public booking engine: on/off, texts, and the link to share. */
export function BookingEngineCard({ initial, disabled }: { initial: BookingEngineSettings; disabled: boolean }) {
    const [state, setState] = useState(initial);
    const [form, setForm] = useState({ enabled: initial.enabled, intro: initial.intro ?? '', terms: initial.terms ?? '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const dirty = form.enabled !== state.enabled || form.intro !== (state.intro ?? '') || form.terms !== (state.terms ?? '');

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.put<{ message: string; booking_engine: BookingEngineSettings }>(propertyApiUrl('/settings/booking-engine'), {
                enabled: form.enabled, intro: form.intro.trim() || null, terms: form.terms.trim() || null,
            });
            setState(res.booking_engine);
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };
    const copy = async () => {
        try { await navigator.clipboard.writeText(state.url); toast.success(t('property.booking_engine.copied')); } catch { /* clipboard blocked: the link stays selectable */ }
    };

    return (
        <Card title={t('property.booking_engine.title')} actions={<a className="btn btn-outline btn-sm" href={state.url} target="_blank" rel="noopener">{t('property.booking_engine.open_page')}</a>}>
            <div className="form-grid">
                <p className="span-12 muted text-sm">{t('property.booking_engine.description')}</p>
                <div className="span-12">
                    <Alert tone={state.open ? 'success' : 'warn'}>{state.open ? t('property.booking_engine.status_open') : t(`property.booking_engine.reasons.${state.reason}`)}</Alert>
                </div>
                <div className="field span-8">
                    <span className="field-label">{t('property.booking_engine.link')}</span>
                    <div className="row" style={{ gap: 8 }}>
                        <input className="control grow" readOnly value={state.url} onFocus={(e) => e.target.select()} aria-label={t('property.booking_engine.link')} />
                        <Button variant="outline" icon="copy" onClick={copy}>{t('property.booking_engine.copy')}</Button>
                    </div>
                </div>
                <div className="field span-4">
                    <span className="field-label">&nbsp;</span>
                    <Toggle checked={form.enabled} disabled={disabled} onChange={(v) => setForm({ ...form, enabled: v })} label={t('property.booking_engine.enabled')} />
                </div>
                <Input fieldClass="span-12" label={t('property.booking_engine.intro')} optional maxLength={300} disabled={disabled} value={form.intro}
                    onChange={(e) => setForm({ ...form, intro: e.target.value })} hint={t('property.booking_engine.intro_hint')} error={error?.field('intro')} />
                <Textarea fieldClass="span-12" label={t('property.booking_engine.terms')} optional rows={4} maxLength={3000} disabled={disabled} value={form.terms}
                    onChange={(e) => setForm({ ...form, terms: e.target.value })} hint={t('property.booking_engine.terms_hint')} error={error?.field('terms')} />
                {!disabled && <div className="span-12 row" style={{ justifyContent: 'flex-end' }}>
                    <Button variant="primary" icon="save" loading={busy} disabled={!dirty} onClick={save}>{t('ui.save_changes')}</Button>
                </div>}
            </div>
        </Card>
    );
}
