import { Checkbox, Input, Select } from '@/components/ui';
import type { ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

/** Form values of a calendar edit, as strings (inputs). '' = leave unchanged. */
export interface AriValues {
    price: string;
    occ: Record<string, string>;
    min_los: string;
    max_los: string;
    min_advance: string;
    max_advance: string;
    cta: string;            // '' | '1' | '0'
    ctd: string;
    closed: string;
    stop_sell: string;
    sell_limit: string;
    remove_limit: boolean;
}

export const blankValues = (): AriValues => ({
    price: '', occ: {}, min_los: '', max_los: '', min_advance: '', max_advance: '',
    cta: '', ctd: '', closed: '', stop_sell: '', sell_limit: '', remove_limit: false,
});

const NUMBERS = ['min_los', 'max_los', 'min_advance', 'max_advance'] as const;
const FLAGS = ['cta', 'ctd', 'closed', 'stop_sell'] as const;

/**
 * Request body with only what changed. With `initial` (single cell) a value equal to the stored one is
 * not sent and a cleared stay / booking-window field means "remove" (0); without it (range, bulk) an
 * empty field is simply left alone.
 */
export function toPayload(v: AriValues, initial?: AriValues): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    if (v.price !== '' && v.price !== initial?.price) out.price = v.price;
    const occ: Record<string, string | null> = {};
    for (const [adults, value] of Object.entries(v.occ)) {
        const before = initial?.occ[adults] ?? '';
        if (value !== before && (value !== '' || initial)) occ[adults] = value === '' ? null : value;
    }
    if (Object.keys(occ).length) out.occupancy_prices = occ;
    for (const key of NUMBERS) {
        if (v[key] === (initial?.[key] ?? '')) continue;
        if (v[key] === '') {
            if (initial) out[key] = 0;
        } else out[key] = Number(v[key]);
    }
    for (const key of FLAGS) {
        if (v[key] !== '' && v[key] !== (initial?.[key] ?? '')) out[key] = v[key] === '1';
    }
    if (v.remove_limit) out.sell_limit = 'none';
    else if (v.sell_limit !== '' && v.sell_limit !== (initial?.sell_limit ?? '')) out.sell_limit = Number(v.sell_limit);
    return out;
}

interface Props {
    values: AriValues;
    onChange: (v: AriValues) => void;
    error: ApiError | null;
    /** Which groups of fields to show. */
    roomType?: boolean;
    product?: boolean;
    /** Single cell: yes/no only (no "leave unchanged"). */
    single?: boolean;
    priceLocked?: boolean;
    restrictionsLocked?: boolean;
    /** Numbers of adults that can have their own price (other than the base occupancy). */
    occupancies?: number[];
    compact?: boolean;
}

export function AriFields({ values, onChange, error, roomType, product, single, priceLocked, restrictionsLocked, occupancies = [], compact }: Props) {
    const set = <K extends keyof AriValues>(key: K, value: AriValues[K]) => onChange({ ...values, [key]: value });
    const e = (name: string) => error?.field(name) ?? null;
    const span = compact ? 'span-6' : 'span-4';
    const yesNo = (yes: string, no: string) => [
        ...(single ? [] : [{ value: '', label: t('calendar.edit.keep') }]),
        { value: '1', label: yes },
        { value: '0', label: no },
    ];
    const num = (key: (typeof NUMBERS)[number], disabled = false) => (
        <Input key={key} fieldClass={span} label={t(`calendar.fields.${key}`)} type="number" min={0} max={999} step={1} inputMode="numeric"
            value={values[key]} disabled={disabled} placeholder={single ? '' : t('calendar.edit.keep')} error={e(key)}
            onChange={(ev) => set(key, ev.target.value)} />
    );
    const flag = (key: (typeof FLAGS)[number], yes: string, no: string, disabled = false) => (
        <Select key={key} fieldClass={span} label={t(`calendar.fields.${key}`)} value={values[key]} disabled={disabled} error={e(key)}
            options={yesNo(yes, no)} onChange={(ev) => set(key, ev.target.value)} />
    );

    // Bare fields: the caller provides the 12-column grid (FormSection already has one).
    return (
        <>
            {roomType && (
                <>
                    {flag('stop_sell', t('calendar.edit.close'), t('calendar.edit.open'))}
                    <Input fieldClass={span} label={t('calendar.fields.sell_limit')} type="number" min={0} step={1} inputMode="numeric"
                        value={values.sell_limit} disabled={values.remove_limit} placeholder={single ? '' : t('calendar.edit.keep')} error={e('sell_limit')}
                        onChange={(ev) => set('sell_limit', ev.target.value)} />
                    <div className={`field ${compact ? 'span-12' : 'span-4'} field-inline`}>
                        <Checkbox label={t('calendar.edit.remove_limit')} checked={values.remove_limit} onChange={(ev) => set('remove_limit', ev.target.checked)} />
                    </div>
                </>
            )}
            {product && (
                <>
                    <Input fieldClass={span} label={t('calendar.fields.price')} type="number" min={0} step="0.01" inputMode="decimal"
                        value={values.price} disabled={priceLocked} placeholder={single ? '' : t('calendar.edit.keep')} error={e('price')}
                        onChange={(ev) => set('price', ev.target.value)} />
                    {!priceLocked && occupancies.map((adults) => (
                        <Input key={adults} fieldClass={span} label={t('calendar.fields.occupancy_price', { count: adults })} type="number" min={0} step="0.01" inputMode="decimal"
                            value={values.occ[String(adults)] ?? ''} placeholder={single ? '' : t('calendar.edit.keep')} error={e(`occupancy_prices.${adults}`)}
                            onChange={(ev) => set('occ', { ...values.occ, [String(adults)]: ev.target.value })} />
                    ))}
                    {num('min_los', restrictionsLocked)}
                    {num('max_los', restrictionsLocked)}
                    {flag('cta', t('calendar.edit.yes'), t('calendar.edit.no'), restrictionsLocked)}
                    {flag('ctd', t('calendar.edit.yes'), t('calendar.edit.no'), restrictionsLocked)}
                    {num('min_advance', restrictionsLocked)}
                    {num('max_advance', restrictionsLocked)}
                    {flag('closed', t('calendar.edit.close'), t('calendar.edit.open'))}
                </>
            )}
        </>
    );
}
