import { useMemo, useState } from 'react';
import { Alert, Button, Checkbox, FormSection, Input, Modal, Select } from '@/components/ui';
import { ApiError, http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { number } from '@/lib/format';
import type { SaveResult } from './CellEditor';
import type { Option } from './types';

interface Props {
    onClose: () => void;
    onSaved: (res: SaveResult) => void;
    roomTypes: Option[];
    ratePlans: Option[];
    minDate: string;
    initial: { sourceFrom: string; sourceTo: string; targetFrom: string; targetTo: string; roomTypes: string[]; ratePlans: string[] };
}

interface CopyResult {
    changed: number;
    ari_rows: number;
    occupancy_rows: number;
    preview: boolean;
    target_products: number;
    target_dates: number;
    mapped_dates: number;
    skipped: { reason: string; label: string; dates: number }[];
}

const nights = (from: string, to: string) => (!from || !to || to < from ? 0 : Math.round((Date.parse(to) - Date.parse(from)) / 86400000) + 1);

/**
 * "Copy Values": rates and/or restrictions of a source date range (same or another rate plan) onto a
 * target date range. Preview first (exact counts, nothing saved), then confirm with Copy.
 */
export function CopyDialog({ onClose, onSaved, roomTypes, ratePlans, minDate, initial }: Props) {
    const [form, setForm] = useState({
        source_from: initial.sourceFrom, source_to: initial.sourceTo, source_rate_plan_id: '',
        target_from: initial.targetFrom, target_to: initial.targetTo,
        rate_plan_ids: initial.ratePlans, room_type_ids: initial.roomTypes,
        copy_rates: true, copy_restrictions: true, align_weekdays: true,
    });
    const [preview, setPreview] = useState<{ key: string; result: CopyResult } | null>(null);
    const [busy, setBusy] = useState<'preview' | 'copy' | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const key = useMemo(() => JSON.stringify(form), [form]);
    const fresh = preview?.key === key ? preview.result : null;
    const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }));
    const flip = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

    const body = () => ({ ...form, source_rate_plan_id: form.source_rate_plan_id || null });

    const run = async (mode: 'preview' | 'copy') => {
        setBusy(mode);
        setError(null);
        try {
            if (mode === 'preview') {
                const res = await http.post<{ result: CopyResult }>(propertyApiUrl('/calendar/copy/preview'), body());
                setPreview({ key, result: res.result });
            } else {
                const res = await http.post<{ message: string; result: CopyResult }>(propertyApiUrl('/calendar/copy'), body());
                onSaved({ message: res.message, result: { changed: res.result.changed, skipped: res.result.skipped } });
            }
        } catch (e) {
            setError(e instanceof ApiError ? e : new ApiError((e as Error).message, 0, 'CLIENT'));
        } finally {
            setBusy(null);
        }
    };

    const f = (name: string) => error?.field(name) ?? null;
    const listError = (prefix: string) => error ? Object.entries(error.fields).find(([k]) => k === prefix || k.startsWith(prefix + '.'))?.[1]?.[0] ?? null : null;
    const general = error && (f('copy') ?? f('targets') ?? (Object.keys(error.fields).length === 0 ? error.message : null));

    return (
        <Modal open size="lg" title={t('calendar.copy.title')} onClose={onClose} footer={<>
            <span className="muted num cal-bulk-count">
                {t('calendar.copy.summary', { source: nights(form.source_from, form.source_to), target: nights(form.target_from, form.target_to) })}
            </span>
            <Button variant="secondary" onClick={onClose}>{t('calendar.edit.cancel')}</Button>
            <Button variant="secondary" icon="eye" loading={busy === 'preview'} onClick={() => run('preview')}>{t('calendar.copy.preview')}</Button>
            <Button variant="primary" icon="copy" loading={busy === 'copy'} disabled={!fresh || fresh.changed === 0}
                title={fresh ? undefined : t('calendar.copy.preview_first')} onClick={() => run('copy')}>
                {fresh ? t('calendar.copy.confirm', { count: number(fresh.changed) }) : t('calendar.copy.copy')}
            </Button>
        </>}>
            <div className="cal-copy">
                {general && <Alert tone="danger">{general}</Alert>}
                <FormSection title={t('calendar.copy.source')} description={t('calendar.copy.source_hint')}>
                    <Input fieldClass="span-4" type="date" label={t('calendar.copy.source_from')} value={form.source_from} error={f('source_from')} onChange={(e) => set('source_from', e.target.value)} />
                    <Input fieldClass="span-4" type="date" label={t('calendar.copy.source_to')} value={form.source_to} min={form.source_from} error={f('source_to')} onChange={(e) => set('source_to', e.target.value)} />
                    <Select fieldClass="span-4" label={t('calendar.copy.source_rate_plan')} value={form.source_rate_plan_id} error={f('source_rate_plan_id')}
                        options={[{ value: '', label: t('calendar.copy.same_rate_plan') }, ...ratePlans]} onChange={(e) => set('source_rate_plan_id', e.target.value)} />
                </FormSection>

                <FormSection title={t('calendar.copy.target')}>
                    <Input fieldClass="span-6" type="date" label={t('calendar.copy.target_from')} value={form.target_from} min={minDate} error={f('target_from')} onChange={(e) => set('target_from', e.target.value)} />
                    <Input fieldClass="span-6" type="date" label={t('calendar.copy.target_to')} value={form.target_to} min={form.target_from || minDate} error={f('target_to')} onChange={(e) => set('target_to', e.target.value)} />
                    <div className="field span-6">
                        <span className="field-label">{t('calendar.copy.target_rate_plans')}</span>
                        <div className="cal-checklist">
                            {ratePlans.map((o) => <Checkbox key={o.value} label={o.label} checked={form.rate_plan_ids.includes(o.value)} onChange={() => set('rate_plan_ids', flip(form.rate_plan_ids, o.value))} />)}
                        </div>
                        {listError('rate_plan_ids') && <div className="field-error" role="alert">{listError('rate_plan_ids')}</div>}
                    </div>
                    <div className="field span-6">
                        <span className="field-label">{t('calendar.bulk.room_types')} <span className="muted">({t('calendar.copy.optional')})</span></span>
                        <div className="cal-checklist">
                            {roomTypes.map((o) => <Checkbox key={o.value} label={o.label} checked={form.room_type_ids.includes(o.value)} onChange={() => set('room_type_ids', flip(form.room_type_ids, o.value))} />)}
                        </div>
                        <div className="field-hint">{t('calendar.copy.room_types_hint')}</div>
                        {listError('room_type_ids') && <div className="field-error" role="alert">{listError('room_type_ids')}</div>}
                    </div>
                </FormSection>

                <FormSection title={t('calendar.copy.what')}>
                    <div className="field span-6 cal-copy-option">
                        <Checkbox label={t('calendar.copy.rates')} checked={form.copy_rates} onChange={(e) => set('copy_rates', e.target.checked)} />
                        <div className="field-hint">{t('calendar.copy.rates_hint')}</div>
                    </div>
                    <div className="field span-6 cal-copy-option">
                        <Checkbox label={t('calendar.copy.restrictions')} checked={form.copy_restrictions} onChange={(e) => set('copy_restrictions', e.target.checked)} />
                        <div className="field-hint">{t('calendar.copy.restrictions_hint')}</div>
                    </div>
                    <div className="field span-12 cal-copy-option">
                        <Checkbox label={t('calendar.copy.align_weekdays')} checked={form.align_weekdays} onChange={(e) => set('align_weekdays', e.target.checked)} />
                        <div className="field-hint">{t('calendar.copy.align_hint')}</div>
                    </div>
                </FormSection>

                {preview && (
                    <div className={fresh ? 'cal-copy-preview' : 'cal-copy-preview stale'} aria-live="polite">
                        <b>{t('calendar.copy.preview_title')}</b>
                        <p className="num">{t('calendar.copy.preview_text', { changed: number(preview.result.changed), products: preview.result.target_products, dates: preview.result.mapped_dates })}</p>
                        {preview.result.skipped.length > 0 && (
                            <ul>{preview.result.skipped.map((s) => <li key={s.reason}>{s.label} <span className="muted num">({s.dates})</span></li>)}</ul>
                        )}
                        {!fresh && <small className="muted">{t('calendar.copy.preview_stale')}</small>}
                    </div>
                )}
            </div>
        </Modal>
    );
}
