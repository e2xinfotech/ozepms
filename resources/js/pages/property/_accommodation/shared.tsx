import { useState } from 'react';
import { Button, Icon, Input, Select, toast, type Option } from '@/components/ui';
import { money } from '@/lib/format';
import { ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { payload } from '@/lib/page';

/** Helpers shared by the room type, room, rate plan and tax pages. */

export function currency(): string {
    return payload().shell.property?.currency ?? '';
}

export function price(amount: string | number | null | undefined): string {
    return money(amount, currency());
}

/** Runs an API call, shows the server message as a toast and returns the result (undefined on error). */
export async function act<T extends { message?: string }>(call: () => Promise<T>, onError?: (e: ApiError) => void): Promise<T | undefined> {
    try {
        const res = await call();
        if (res?.message) toast.success(res.message);
        return res;
    } catch (e) {
        const err = e instanceof ApiError ? e : new ApiError((e as Error).message, 0, 'CLIENT_ERROR');
        if (onError && err.status === 422) onError(err);
        else toast.error(err.message, err.ref);
        if (onError && err.status === 422 && Object.keys(err.fields).length === 0) toast.error(err.message, err.ref);
        return undefined;
    }
}

/** First error of a field, also matching nested keys ("products.0.default_price"). */
export function fieldError(error: ApiError | null, key: string): string | undefined {
    return error?.field(key);
}

/** Errors whose key starts with a prefix, flattened for an alert under a block. */
export function errorsUnder(error: ApiError | null, prefix: string): string[] {
    if (!error) return [];
    return Object.entries(error.fields).filter(([k]) => k === prefix || k.startsWith(prefix + '.')).flatMap(([, v]) => v);
}

export function Occupancy({ adults, children }: { adults: number; children?: number }) {
    return (
        <span className="occ" title={t('rooms.columns.max_occupancy')}>
            <Icon name="user" size={16} />{adults}
            {children ? <><Icon name="baby" size={15} />{children}</> : null}
        </span>
    );
}

export function MoneyInput({ value, onChange, error, label, placeholder, allowNegative, fieldClass, suffix }: {
    value: string; onChange: (v: string) => void; error?: string; label?: string; placeholder?: string;
    allowNegative?: boolean; fieldClass?: string; suffix?: string;
}) {
    return (
        <Input label={label} type="number" inputMode="decimal" step="0.01" min={allowNegative ? undefined : 0} value={value}
            placeholder={placeholder} onChange={(e) => onChange(e.target.value)} error={error} fieldClass={fieldClass}
            suffix={suffix ?? currency()} className="num" />
    );
}

export interface OccupancyRule {
    guest_type: 'adult' | 'child' | 'infant';
    guest_count: number;
    age_band: string | null;
    adjust_type: 'fixed' | 'percent';
    adjust_value: string;
}

/** Editable list of occupancy price adjustments of one product. */
export function OccupancyEditor({ rules, onChange, ageBands, errorPrefix, error }: {
    rules: OccupancyRule[]; onChange: (rules: OccupancyRule[]) => void; ageBands: Option[]; errorPrefix: string; error: ApiError | null;
}) {
    const set = (i: number, patch: Partial<OccupancyRule>) => onChange(rules.map((r, j) => (j === i ? { ...r, ...patch } : r)));
    const guestTypes: Option[] = [
        { value: 'adult', label: t('rates.occupancy.adult') },
        { value: 'child', label: t('rates.occupancy.child') },
        { value: 'infant', label: t('rates.occupancy.infant') },
    ];
    return (
        <div className="edit-rows">
            {rules.length === 0 && <p className="muted text-sm">{t('rates.occupancy.none')}</p>}
            {rules.map((r, i) => (
                <div key={i} className="inline-fields">
                    <Select size="sm" aria-label={t('rates.occupancy.guest')} value={r.guest_type} options={guestTypes}
                        onChange={(e) => set(i, { guest_type: e.target.value as OccupancyRule['guest_type'], age_band: e.target.value === 'adult' ? null : r.age_band })} />
                    <Input size="sm" type="number" min={1} aria-label={t('rates.occupancy.count')} title={t('rates.occupancy.count_hint')} value={r.guest_count}
                        onChange={(e) => set(i, { guest_count: Number(e.target.value) })} error={fieldError(error, `${errorPrefix}.${i}.guest_count`)} />
                    <Select size="sm" aria-label={t('rates.occupancy.age_band')} value={r.age_band ?? ''} disabled={r.guest_type === 'adult'}
                        placeholder={t('rates.any')} options={ageBands} onChange={(e) => set(i, { age_band: e.target.value || null })} />
                    <Select size="sm" aria-label={t('rates.fields.adjust_type')} value={r.adjust_type}
                        options={[{ value: 'fixed', label: t('rates.occupancy_types.fixed') }, { value: 'percent', label: t('rates.occupancy_types.percent') }]}
                        onChange={(e) => set(i, { adjust_type: e.target.value as OccupancyRule['adjust_type'] })} />
                    <Input size="sm" type="number" step="0.01" aria-label={t('rates.fields.adjust_value')} value={r.adjust_value} className="num"
                        onChange={(e) => set(i, { adjust_value: e.target.value })} error={fieldError(error, `${errorPrefix}.${i}.adjust_value`)} />
                    <Button size="sm" variant="ghost" icon="trash" title={t('ui.delete')} aria-label={t('ui.delete')} onClick={() => onChange(rules.filter((_, j) => j !== i))} />
                </div>
            ))}
            <div>
                <Button size="sm" variant="outline" icon="plus" onClick={() => onChange([...rules, { guest_type: 'adult', guest_count: 1, age_band: null, adjust_type: 'fixed', adjust_value: '0' }])}>
                    {t('rates.occupancy.add')}
                </Button>
            </div>
        </div>
    );
}

/** Image carousel used in detail panels. */
export function Carousel({ images, alt }: { images: { id: number; url: string; alt?: string | null }[]; alt: string }) {
    const [i, setI] = useState(0);
    if (images.length === 0) return <div className="carousel empty" title={alt}><Icon name="image" size={32} /></div>;
    const current = images[Math.min(i, images.length - 1)];
    return (
        <div className="carousel">
            <img src={current.url} alt={current.alt || alt} />
            {images.length > 1 && <>
                <button type="button" className="nav prev" aria-label={t('ui.previous')} title={t('ui.previous')} onClick={() => setI((i - 1 + images.length) % images.length)}><Icon name="chevron-left" size={18} /></button>
                <button type="button" className="nav next" aria-label={t('ui.next')} title={t('ui.next')} onClick={() => setI((i + 1) % images.length)}><Icon name="chevron-right" size={18} /></button>
            </>}
            <span className="count num">{Math.min(i, images.length - 1) + 1} / {images.length}</span>
        </div>
    );
}

/** Translated label of a value from an option list. */
export function labelOf(options: Option[], value: string | number | null | undefined): string {
    return options.find((o) => String(o.value) === String(value))?.label ?? (value === null || value === undefined ? '—' : String(value));
}
