import { useEffect, useState } from 'react';
import { Checkbox, Field, FormSection, Input, Select, Textarea, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { PropertyDetail, PropertyLookups } from './types';

/** Values of the shared property form (onboarding, property settings, platform create/edit). */
export interface PropertyFormValues {
    name: string;
    tagline: string;
    legal_name: string;
    property_type_id: string;
    star_rating: string;
    phone: string;
    email: string;
    website: string;
    description: string;
    contact_person: string;
    country_iso2: string;
    state_id: string;
    city: string;
    postcode: string;
    address_line1: string;
    address_line2: string;
    latitude: string;
    longitude: string;
    maps_url: string;
    currency_code: string;
    timezone: string;
    date_format: string;
    number_format: string;
    week_start: string;
    check_in_time: string;
    check_out_time: string;
    tax_registration_no: string;
    default_language: string;
    languages: string[];
}

const s = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(v));

export function emptyPropertyValues(lookups: PropertyLookups): PropertyFormValues {
    const d = lookups.defaults;
    return {
        name: '', tagline: '', legal_name: '', property_type_id: s(lookups.types[0]?.value), star_rating: '',
        phone: '', email: '', website: '', description: '', contact_person: '',
        country_iso2: d.country, state_id: '', city: '', postcode: '', address_line1: '', address_line2: '',
        latitude: '', longitude: '', maps_url: '',
        currency_code: d.currency, timezone: d.timezone, date_format: lookups.date_formats[0] ?? '', number_format: lookups.number_formats[0] ?? '',
        week_start: '1', check_in_time: d.check_in, check_out_time: d.check_out, tax_registration_no: '',
        default_language: 'en', languages: [],
    };
}

export function propertyValues(p: PropertyDetail): PropertyFormValues {
    return {
        name: p.name, tagline: s(p.tagline), legal_name: s(p.legal_name), property_type_id: s(p.property_type_id), star_rating: s(p.star_rating),
        phone: s(p.phone), email: s(p.email), website: s(p.website), description: s(p.description), contact_person: s(p.contact_person),
        country_iso2: s(p.country_iso2), state_id: s(p.state_id), city: s(p.city), postcode: s(p.postcode),
        address_line1: s(p.address_line1), address_line2: s(p.address_line2), latitude: s(p.latitude), longitude: s(p.longitude), maps_url: s(p.maps_url),
        currency_code: p.currency_code, timezone: p.timezone, date_format: s(p.date_format), number_format: s(p.number_format),
        week_start: s(p.week_start), check_in_time: s(p.check_in_time), check_out_time: s(p.check_out_time),
        tax_registration_no: s(p.tax_registration_no), default_language: p.default_language, languages: p.languages ?? [],
    };
}

/** JSON body for the API: empty strings become null, numbers become numbers. */
export function propertyPayload(v: PropertyFormValues): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(v)) {
        out[key] = Array.isArray(value) ? value : value === '' ? null : value;
    }
    for (const key of ['property_type_id', 'star_rating', 'state_id', 'week_start']) {
        if (out[key] !== null) out[key] = Number(out[key]);
    }
    return out;
}

/**
 * The property form as titled sections on the 12-column grid. The caller owns
 * saving and the footer buttons.
 */
export function PropertyForm({ value, onChange, lookups, error, disabled, autoRegional }: {
    value: PropertyFormValues;
    onChange: (v: PropertyFormValues) => void;
    lookups: PropertyLookups;
    error?: ApiError | null;
    disabled?: boolean;
    /** When true, choosing a country also fills in its currency and time zone. */
    autoRegional?: boolean;
}) {
    const [states, setStates] = useState<Option[]>([]);
    const set = <K extends keyof PropertyFormValues>(key: K, v: PropertyFormValues[K]) => onChange({ ...value, [key]: v });
    const err = (name: string) => error?.field(name);

    useEffect(() => {
        let alive = true;
        if (!value.country_iso2) {
            setStates([]);
            return;
        }
        http.get<{ states: Option[] }>('/web-api/lookups/states', { country: value.country_iso2 })
            .then((res) => alive && setStates(res.states))
            .catch(() => alive && setStates([]));
        return () => { alive = false; };
    }, [value.country_iso2]);

    const changeCountry = (code: string) => {
        const country = lookups.countries.find((c) => c.value === code);
        const next = { ...value, country_iso2: code, state_id: '' };
        if (autoRegional && country) {
            if (country.currency && lookups.currencies.some((c) => c.value === country.currency)) next.currency_code = country.currency;
            if (country.timezone) next.timezone = country.timezone;
        }
        onChange(next);
    };

    const stars: Option[] = [1, 2, 3, 4, 5].map((n) => ({ value: n, label: t('property.stars', { n }) }));
    const weekdays: Option[] = ['1', '0', '6'].map((d) => ({ value: d, label: t(`property.weekdays.${d}`) }));
    const toOptions = (list: string[]) => list.map((x) => ({ value: x, label: x }));

    return (
        <>
            <FormSection title={t('property.sections.general')}>
                <Input fieldClass="span-6" label={t('property.name')} required value={value.name} disabled={disabled} onChange={(e) => set('name', e.target.value)} error={err('name')} maxLength={150} />
                <Select fieldClass="span-3" label={t('property.type')} required value={value.property_type_id} disabled={disabled} options={lookups.types} onChange={(e) => set('property_type_id', e.target.value)} error={err('property_type_id')} />
                <Select fieldClass="span-3" label={t('property.star_rating')} optional value={value.star_rating} disabled={disabled} options={stars} placeholder={t('ui.none')} onChange={(e) => set('star_rating', e.target.value)} error={err('star_rating')} />
                <Input fieldClass="span-6" label={t('property.tagline')} optional value={value.tagline} disabled={disabled} onChange={(e) => set('tagline', e.target.value)} error={err('tagline')} maxLength={150} />
                <Input fieldClass="span-6" label={t('property.legal_name')} optional value={value.legal_name} disabled={disabled} onChange={(e) => set('legal_name', e.target.value)} error={err('legal_name')} />
                <Input fieldClass="span-4" label={t('property.contact_person')} optional value={value.contact_person} disabled={disabled} onChange={(e) => set('contact_person', e.target.value)} error={err('contact_person')} />
                <Input fieldClass="span-4" label={t('property.email')} optional type="email" icon="mail" value={value.email} disabled={disabled} onChange={(e) => set('email', e.target.value)} error={err('email')} />
                <Input fieldClass="span-4" label={t('property.phone')} optional icon="phone" value={value.phone} disabled={disabled} onChange={(e) => set('phone', e.target.value)} error={err('phone')} />
                <Input fieldClass="span-6" label={t('property.website')} optional type="url" icon="globe" placeholder="https://" value={value.website} disabled={disabled} onChange={(e) => set('website', e.target.value)} error={err('website')} />
                <Input fieldClass="span-6" label={t('property.tax_registration_no')} optional value={value.tax_registration_no} disabled={disabled} onChange={(e) => set('tax_registration_no', e.target.value)} error={err('tax_registration_no')} />
                <Textarea fieldClass="span-12" label={t('property.description')} optional rows={3} value={value.description} disabled={disabled} onChange={(e) => set('description', e.target.value)} error={err('description')} />
            </FormSection>

            <FormSection title={t('property.sections.location')}>
                <Select fieldClass="span-4" label={t('property.country')} required value={value.country_iso2} disabled={disabled} options={lookups.countries} onChange={(e) => changeCountry(e.target.value)} error={err('country_iso2')} />
                <Select fieldClass="span-4" label={t('property.state')} optional value={value.state_id} disabled={disabled || states.length === 0} options={states} placeholder={t('ui.none')} onChange={(e) => set('state_id', e.target.value)} error={err('state_id')} />
                <Input fieldClass="span-4" label={t('property.city')} optional value={value.city} disabled={disabled} onChange={(e) => set('city', e.target.value)} error={err('city')} />
                <Input fieldClass="span-8" label={t('property.address_line1')} optional value={value.address_line1} disabled={disabled} onChange={(e) => set('address_line1', e.target.value)} error={err('address_line1')} />
                <Input fieldClass="span-4" label={t('property.postcode')} optional value={value.postcode} disabled={disabled} onChange={(e) => set('postcode', e.target.value)} error={err('postcode')} />
                <Input fieldClass="span-12" label={t('property.address_line2')} optional value={value.address_line2} disabled={disabled} onChange={(e) => set('address_line2', e.target.value)} error={err('address_line2')} />
                <Input fieldClass="span-3" label={t('property.latitude')} optional inputMode="decimal" value={value.latitude} disabled={disabled} onChange={(e) => set('latitude', e.target.value)} error={err('latitude')} />
                <Input fieldClass="span-3" label={t('property.longitude')} optional inputMode="decimal" value={value.longitude} disabled={disabled} onChange={(e) => set('longitude', e.target.value)} error={err('longitude')} />
                <Input fieldClass="span-6" label={t('property.maps_url')} optional type="url" icon="map-pin" value={value.maps_url} disabled={disabled} onChange={(e) => set('maps_url', e.target.value)} error={err('maps_url')} />
            </FormSection>

            <FormSection title={t('property.sections.regional')}>
                <Select fieldClass="span-4" label={t('property.currency')} required value={value.currency_code} disabled={disabled} options={lookups.currencies} onChange={(e) => set('currency_code', e.target.value)} error={err('currency_code')} />
                <Select fieldClass="span-4" label={t('property.timezone')} required value={value.timezone} disabled={disabled} options={lookups.timezones} onChange={(e) => set('timezone', e.target.value)} error={err('timezone')} />
                <Select fieldClass="span-4" label={t('property.week_start')} value={value.week_start} disabled={disabled} options={weekdays} onChange={(e) => set('week_start', e.target.value)} error={err('week_start')} />
                <Select fieldClass="span-4" label={t('property.date_format')} value={value.date_format} disabled={disabled} options={toOptions(lookups.date_formats)} onChange={(e) => set('date_format', e.target.value)} error={err('date_format')} />
                <Select fieldClass="span-4" label={t('property.number_format')} value={value.number_format} disabled={disabled} options={toOptions(lookups.number_formats)} onChange={(e) => set('number_format', e.target.value)} error={err('number_format')} />
                <Input fieldClass="span-2" label={t('property.check_in_time')} type="time" icon="clock" value={value.check_in_time} disabled={disabled} onChange={(e) => set('check_in_time', e.target.value)} error={err('check_in_time')} />
                <Input fieldClass="span-2" label={t('property.check_out_time')} type="time" icon="clock" value={value.check_out_time} disabled={disabled} onChange={(e) => set('check_out_time', e.target.value)} error={err('check_out_time')} />
            </FormSection>

            <FormSection title={t('property.sections.language')}>
                <Select fieldClass="span-4" label={t('property.default_language')} required value={value.default_language} disabled={disabled} options={lookups.languages} onChange={(e) => set('default_language', e.target.value)} error={err('default_language')} />
                <Field className="span-8" label={t('property.other_languages')} optional error={err('languages')}>
                    <div className="row" style={{ flexWrap: 'wrap', gap: 18, minHeight: 40 }}>
                        {lookups.languages.filter((l) => l.value !== value.default_language).map((l) => (
                            <Checkbox key={l.value} label={l.label} disabled={disabled} checked={value.languages.includes(String(l.value))}
                                onChange={(e) => set('languages', e.target.checked ? [...value.languages, String(l.value)] : value.languages.filter((x) => x !== l.value))} />
                        ))}
                    </div>
                </Field>
            </FormSection>
        </>
    );
}
