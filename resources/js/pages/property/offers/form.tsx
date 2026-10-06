import { useRef, useState } from 'react';
import { Alert, Button, Checkbox, FormSection, Icon, Input, LinkButton, PageHeader, Select, Textarea, Toggle, toast, type Option } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, currency, fieldError } from '../_accommodation/shared';
import { DAYS, DISCOUNT_TYPES, OFFER_TYPES, type OfferForm as OfferValues } from './_components/types';

interface Props {
    offer: OfferValues | null;
    options: { room_types: Option[]; rate_plans: Option[]; sources: Option[]; countries: Option[] };
}

const str = (v: number | string | null | undefined) => (v === null || v === undefined ? '' : String(v));
const num = (v: string) => (v.trim() === '' ? null : Number(v));

/** Create / edit an offer or promotion (spec §32): discount, windows, stay rules, rooms, channels, conditions. */
function OfferFormPage({ offer: o, options }: Props) {
    const editing = o !== null;
    const cur = currency();
    const [data, setData] = useState({
        name: o?.name ?? '', code: o?.code ?? '', offer_type: o?.offer_type ?? 'room_discount', description: o?.description ?? '',
        promo_code: o?.promo_code ?? '', discount_type: o?.discount_type ?? 'percent', discount_value: o ? String(Number(o.discount_value)) : '10',
        min_nights: str(o?.min_nights), max_nights: str(o?.max_nights), min_amount: o?.min_amount ? String(Number(o.min_amount)) : '',
        booking_from: o?.booking_from ?? '', booking_to: o?.booking_to ?? '', stay_from: o?.stay_from ?? '', stay_to: o?.stay_to ?? '',
        weekdays: o?.weekdays ?? 127, min_advance_days: str(o?.min_advance_days), max_advance_days: str(o?.max_advance_days),
        priority: str(o?.priority ?? 0), is_stackable: o?.is_stackable ?? false, max_redemptions: str(o?.max_redemptions),
        on_pms: o?.on_pms ?? true, on_booking_engine: o?.on_booking_engine ?? true, is_active: o?.is_active ?? true,
        room_types: o?.room_types ?? [], rate_plans: o?.rate_plans ?? [], sources: o?.sources ?? [], countries: o?.countries ?? [],
        country_mode: o?.country_mode ?? 'in', min_adults: str(o?.min_adults), min_rooms: str(o?.min_rooms),
    });
    type Data = typeof data;
    const set = <K extends keyof Data>(key: K, value: Data[K]) => setData((d) => ({ ...d, [key]: value }));
    const toggleIn = (key: 'room_types' | 'rate_plans' | 'sources', value: string) =>
        setData((d) => ({ ...d, [key]: d[key].includes(value) ? d[key].filter((x) => x !== value) : [...d[key], value] }));
    const [country, setCountry] = useState('');
    const [image, setImage] = useState<string | null>(o?.image_url ?? null);
    const [uploading, setUploading] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [saving, setSaving] = useState(false);
    const err = (k: string) => fieldError(error, k);
    const free = data.discount_type === 'free_nights';
    const block = Number(data.min_nights) || 0;
    const freeCount = Math.floor(Number(data.discount_value) || 0);

    const save = async () => {
        setSaving(true);
        setError(null);
        const body = {
            ...data,
            code: data.code.trim() || null, promo_code: data.promo_code.trim() || null, description: data.description.trim() || null,
            min_nights: num(data.min_nights), max_nights: num(data.max_nights), min_amount: data.min_amount.trim() || null,
            booking_from: data.booking_from || null, booking_to: data.booking_to || null, stay_from: data.stay_from || null, stay_to: data.stay_to || null,
            min_advance_days: num(data.min_advance_days), max_advance_days: num(data.max_advance_days), priority: Number(data.priority) || 0,
            max_redemptions: num(data.max_redemptions), min_adults: num(data.min_adults), min_rooms: num(data.min_rooms),
        };
        const res = await act(() => editing
            ? http.put<{ message: string; offer: OfferValues }>(propertyApiUrl(`/offers/${o.id}`), body)
            : http.post<{ message: string; offer: OfferValues }>(propertyApiUrl('/offers'), body), (e) => { setError(e); toast.error(e.message); });
        setSaving(false);
        if (res) window.location.href = propertyUrl(`/offers?selected=${res.offer.id}`);
    };

    const upload = async (file: File | undefined) => {
        if (!file || !o) return;
        setUploading(true);
        const res = await act(() => http.upload<{ message: string; image_url: string }>(propertyApiUrl(`/offers/${o.id}/image`), file), (e) => toast.error(e.field('image') ?? e.message));
        setUploading(false);
        if (res) setImage(res.image_url);
    };
    const removeImage = async () => {
        if (!o) return;
        setUploading(true);
        const res = await act(() => http.delete<{ message: string }>(propertyApiUrl(`/offers/${o.id}/image`)));
        setUploading(false);
        if (res) setImage(null);
    };

    const countryLabel = (iso: string) => options.countries.find((c) => c.value === iso)?.label ?? iso;

    return (
        <div className="content">
            <PageHeader back={propertyUrl('/offers')} title={editing ? `${t('offers.edit')}: ${o.name}` : t('offers.add')} description={t('offers.form.subtitle')} />
            <div className="form-page">
                {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}

                <FormSection title={t('offers.form.sections.basics')}>
                    <Input fieldClass="span-6" label={t('offers.fields.name')} required maxLength={120} value={data.name} onChange={(e) => set('name', e.target.value)} error={err('name')} />
                    <Select fieldClass="span-3" label={t('offers.fields.offer_type')} required value={data.offer_type}
                        options={OFFER_TYPES.map((v) => ({ value: v, label: t(`offers.types.${v}`) }))} onChange={(e) => set('offer_type', e.target.value)} error={err('offer_type')} />
                    <div className="field span-3">
                        <span className="field-label">{t('offers.fields.is_active')}</span>
                        <Toggle checked={data.is_active} onChange={(v) => set('is_active', v)} label={t(`ui.status.${data.is_active ? 'active' : 'inactive'}`)} />
                    </div>
                    <Input fieldClass="span-6" label={t('offers.fields.promo_code')} optional maxLength={30} value={data.promo_code}
                        onChange={(e) => set('promo_code', e.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''))} error={err('promo_code')} hint={t('offers.form.promo_hint')} />
                    <Input fieldClass="span-6" label={t('offers.fields.code')} optional maxLength={30} value={data.code} placeholder="P-00001"
                        onChange={(e) => set('code', e.target.value.toUpperCase())} error={err('code')} hint={t('offers.form.code_hint')} />
                    <Textarea fieldClass="span-12" label={t('offers.fields.description')} optional rows={3} maxLength={2000} value={data.description} onChange={(e) => set('description', e.target.value)} error={err('description')} />
                </FormSection>

                <FormSection title={t('offers.form.sections.discount')}>
                    <Select fieldClass={free ? 'span-4' : 'span-3'} label={t('offers.fields.discount_type')} required value={data.discount_type}
                        options={DISCOUNT_TYPES.map((v) => ({ value: v, label: t(`offers.discount_types.${v}`) }))} onChange={(e) => set('discount_type', e.target.value)} error={err('discount_type')} />
                    {free && <Input fieldClass="span-4" type="number" min={2} max={365} label={t('offers.form.block_nights')} required value={data.min_nights}
                        onChange={(e) => set('min_nights', e.target.value)} error={err('min_nights')} />}
                    <Input fieldClass={free ? 'span-4' : 'span-3'} type="number" min={0} step={free ? 1 : 0.01} required className="num"
                        label={free ? t('offers.form.value_free') : data.discount_type === 'percent' ? t('offers.form.value_percent') : t('offers.form.value_amount', { currency: cur })}
                        suffix={data.discount_type === 'percent' ? '%' : free ? undefined : cur} value={data.discount_value}
                        onChange={(e) => set('discount_value', e.target.value)} error={err('discount_value')} />
                    <Input fieldClass={free ? 'span-6' : 'span-3'} type="number" min={1} label={t('offers.fields.max_redemptions')} optional value={data.max_redemptions}
                        onChange={(e) => set('max_redemptions', e.target.value)} error={err('max_redemptions')}
                        hint={editing && o.redemptions > 0 ? t('offers.conditions.redemptions', { used: o.redemptions, max: data.max_redemptions || '∞' }) : undefined} />
                    <Input fieldClass={free ? 'span-6' : 'span-3'} type="number" min={0} step="0.01" label={t('offers.fields.min_amount')} optional suffix={cur} value={data.min_amount}
                        onChange={(e) => set('min_amount', e.target.value)} error={err('min_amount')} />
                    {free && block >= 2 && freeCount >= 1 && freeCount < block && <div className="span-12 info-box text-sm">
                        <Icon name="info" size={16} /> {t('offers.form.free_example', { stay: block, pay: block - freeCount })}
                    </div>}
                </FormSection>

                <FormSection title={t('offers.form.sections.validity')}>
                    <Input fieldClass="span-3" type="date" label={t('offers.fields.stay_from')} optional value={data.stay_from} onChange={(e) => set('stay_from', e.target.value)} error={err('stay_from')} />
                    <Input fieldClass="span-3" type="date" label={t('offers.fields.stay_to')} optional value={data.stay_to} onChange={(e) => set('stay_to', e.target.value)} error={err('stay_to')} />
                    <Input fieldClass="span-3" type="date" label={t('offers.fields.booking_from')} optional value={data.booking_from} onChange={(e) => set('booking_from', e.target.value)} error={err('booking_from')} />
                    <Input fieldClass="span-3" type="date" label={t('offers.fields.booking_to')} optional value={data.booking_to} onChange={(e) => set('booking_to', e.target.value)} error={err('booking_to')} />
                    <div className="field span-12">
                        <span className="field-label">{t('offers.form.weekdays')}</span>
                        <div className="day-toggles" role="group" aria-label={t('offers.form.weekdays')}>
                            {DAYS.map((d, i) => {
                                const bit = 1 << i;
                                const on = (data.weekdays & bit) !== 0;
                                return <button key={d} type="button" className={on ? 'day-toggle on' : 'day-toggle'} aria-pressed={on}
                                    onClick={() => set('weekdays', on ? data.weekdays & ~bit : data.weekdays | bit)}>{t(`offers.days.${d}`)}</button>;
                            })}
                        </div>
                        <div className="field-hint">{t('offers.form.weekdays_hint')}</div>
                        {err('weekdays') && <div className="field-error">{err('weekdays')}</div>}
                    </div>
                </FormSection>

                <FormSection title={t('offers.form.sections.stay_rules')}>
                    {!free && <Input fieldClass="span-3" type="number" min={1} max={365} label={t('offers.fields.min_nights')} optional value={data.min_nights} onChange={(e) => set('min_nights', e.target.value)} error={err('min_nights')} />}
                    <Input fieldClass="span-3" type="number" min={1} max={365} label={t('offers.fields.max_nights')} optional value={data.max_nights} onChange={(e) => set('max_nights', e.target.value)} error={err('max_nights')} />
                    <Input fieldClass="span-3" type="number" min={0} max={730} label={t('offers.fields.min_advance_days')} optional value={data.min_advance_days} onChange={(e) => set('min_advance_days', e.target.value)} error={err('min_advance_days')} />
                    <Input fieldClass="span-3" type="number" min={0} max={730} label={t('offers.fields.max_advance_days')} optional value={data.max_advance_days} onChange={(e) => set('max_advance_days', e.target.value)} error={err('max_advance_days')} />
                </FormSection>

                <FormSection title={t('offers.form.sections.applies_to')}>
                    <div className="field span-6">
                        <span className="field-label">{t('offers.fields.room_types')}</span>
                        <div className="check-list">
                            {options.room_types.map((r) => <Checkbox key={r.value} checked={data.room_types.includes(String(r.value))} onChange={() => toggleIn('room_types', String(r.value))} label={r.label} />)}
                        </div>
                        <div className="field-hint">{t('offers.form.all_room_types_hint')}</div>
                    </div>
                    <div className="field span-6">
                        <span className="field-label">{t('offers.fields.rate_plans')}</span>
                        <div className="check-list">
                            {options.rate_plans.map((r) => <Checkbox key={r.value} checked={data.rate_plans.includes(String(r.value))} onChange={() => toggleIn('rate_plans', String(r.value))} label={r.label} />)}
                        </div>
                        <div className="field-hint">{t('offers.form.all_rate_plans_hint')}</div>
                    </div>
                </FormSection>

                <FormSection title={t('offers.form.sections.channels')}>
                    <div className="field span-12">
                        <div className="check-grid">
                            <Checkbox checked={data.on_pms} onChange={(e) => set('on_pms', e.target.checked)} label={t('offers.fields.on_pms')} />
                            <Checkbox checked={data.on_booking_engine} onChange={(e) => set('on_booking_engine', e.target.checked)} label={t('offers.fields.on_booking_engine')} />
                        </div>
                        {err('on_pms') && <div className="field-error">{err('on_pms')}</div>}
                    </div>
                    <div className="field span-12">
                        <span className="field-label">{t('offers.fields.sources')}</span>
                        <div className="check-grid">
                            {options.sources.map((s) => <Checkbox key={s.value} checked={data.sources.includes(String(s.value))} onChange={() => toggleIn('sources', String(s.value))} label={s.label} />)}
                        </div>
                        <div className="field-hint">{t('offers.panel.all_sources')}</div>
                    </div>
                    <Select fieldClass="span-6" label={t('offers.fields.countries')} optional value={country} placeholder="—"
                        options={options.countries.filter((c) => !data.countries.includes(String(c.value)))}
                        onChange={(e) => { const v = e.target.value; if (v) set('countries', [...data.countries, v]); setCountry(''); }} error={err('countries')} />
                    <Select fieldClass="span-6" label="&nbsp;" aria-label={t('offers.fields.countries')} value={data.country_mode}
                        options={(['in', 'not_in'] as const).map((m) => ({ value: m, label: t(`offers.form.country_mode.${m}`) }))} onChange={(e) => set('country_mode', e.target.value as 'in' | 'not_in')} />
                    {data.countries.length > 0 && <div className="span-12 chip-row">
                        {data.countries.map((c) => <span key={c} className="chip">{countryLabel(c)}
                            <button type="button" aria-label={t('ui.remove')} onClick={() => set('countries', data.countries.filter((x) => x !== c))}><Icon name="x" size={12} /></button></span>)}
                    </div>}
                    <Input fieldClass="span-4" type="number" min={1} max={20} label={t('offers.fields.min_adults')} optional value={data.min_adults} onChange={(e) => set('min_adults', e.target.value)} error={err('min_adults')} />
                    <Input fieldClass="span-4" type="number" min={1} max={50} label={t('offers.fields.min_rooms')} optional value={data.min_rooms} onChange={(e) => set('min_rooms', e.target.value)} error={err('min_rooms')} />
                    <Input fieldClass="span-4" type="number" min={-999} max={999} label={t('offers.fields.priority')} value={data.priority}
                        onChange={(e) => set('priority', e.target.value)} error={err('priority')} hint={t('offers.form.priority_hint')} />
                    <div className="field span-12">
                        <Checkbox checked={data.is_stackable} onChange={(e) => set('is_stackable', e.target.checked)} label={t('offers.fields.is_stackable')} />
                        <div className="field-hint">{t('offers.form.stackable_hint')}</div>
                    </div>
                </FormSection>

                <FormSection title={t('offers.form.sections.image')}>
                    {!editing ? <p className="span-12 muted">{t('offers.form.save_first')}</p> : <div className="span-12 row" style={{ alignItems: 'flex-start', gap: 16 }}>
                        <div className="offer-image">{image ? <img src={image} alt={o.name} /> : <span className="muted text-sm"><Icon name="image" size={22} /><br />{t('offers.panel.no_image')}</span>}</div>
                        <div className="stack" style={{ gap: 8 }}>
                            <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => { upload(e.target.files?.[0]); e.target.value = ''; }} />
                            <Button variant="outline" icon="upload" loading={uploading} onClick={() => fileRef.current?.click()}>{t('offers.form.upload')}</Button>
                            {image && <Button variant="ghost" icon="trash" disabled={uploading} onClick={removeImage}>{t('offers.form.remove')}</Button>}
                            <span className="field-hint">{t('offers.form.image_hint')}</span>
                        </div>
                    </div>}
                </FormSection>

                <div className="form-footer">
                    <span className="spacer" />
                    <LinkButton href={propertyUrl('/offers')}>{t('ui.cancel')}</LinkButton>
                    <Button variant="primary" icon="save" loading={saving} onClick={save}>{editing ? t('ui.save_changes') : t('offers.add')}</Button>
                </div>
            </div>
        </div>
    );
}

createPage(OfferFormPage);
