import { useState } from 'react';
import { Alert, Button, Checkbox, Drawer, FormSection, Input, Select, Textarea, toast, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import type { GuestDetail } from './types';

interface Options { countries: Option[]; guest_types: Option[]; titles: Option[]; id_types: Option[] }

/** Add / edit a guest profile (slide-over form). */
export function GuestDrawer({ guest, options, onClose, onSaved }: { guest: GuestDetail | null; options: Options; onClose: () => void; onSaved: (id: string) => void }) {
    const editing = guest !== null;
    const [d, setD] = useState({
        title: guest?.title ?? '', guest_type: guest?.guest_type ?? 'individual', first_name: guest?.first_name ?? '', last_name: guest?.last_name ?? '',
        email: guest?.email ?? '', phone: guest?.phone ?? '', nationality_iso2: guest?.nationality ?? '', country_iso2: guest?.country ?? '',
        date_of_birth: guest?.date_of_birth ?? '', address_line1: guest?.address_line1 ?? '', address_line2: guest?.address_line2 ?? '', city: guest?.city ?? '', postcode: guest?.postcode ?? '',
        company_name: guest?.company_name ?? '', company_tax_no: guest?.company_tax_no ?? '', id_type: guest?.id_type ?? '', id_number: '',
        id_issuing_iso2: guest?.id_issuing ?? '', id_expiry: guest?.id_expiry ?? '', is_vip: guest?.vip ?? false, marketing_consent: guest?.marketing_consent ?? false,
        notes: guest?.notes_text ?? '', preferences: guest?.preferences ?? '',
    });
    const set = <K extends keyof typeof d>(k: K, v: (typeof d)[K]) => setD((x) => ({ ...x, [k]: v }));
    const [error, setError] = useState<ApiError | null>(null);
    const [saving, setSaving] = useState(false);
    const err = (k: string) => error?.field(k);

    const save = async () => {
        if (saving) return;
        setSaving(true);
        setError(null);
        const body: Record<string, unknown> = {};
        for (const [k, v] of Object.entries(d)) body[k] = typeof v === 'string' ? (v.trim() === '' ? null : v) : v;
        if (!d.id_number) delete body.id_number;
        try {
            const res = editing
                ? await http.put<{ message: string; guest: { id: string } }>(propertyApiUrl(`/guests/${guest.id}`), body)
                : await http.post<{ message: string; guest: { id: string } }>(propertyApiUrl('/guests'), body);
            toast.success(res.message);
            onSaved(res.guest.id);
        } catch (e) {
            const er = e as ApiError;
            setError(er);
            toast.error(Object.values(er.fields)[0]?.[0] ?? er.message, er.status >= 500 ? er.ref : undefined);
            setTimeout(() => document.querySelector<HTMLElement>('.drawer .field-error')?.closest('.field')?.querySelector<HTMLElement>('input, select, textarea')?.focus(), 30);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Drawer open size="lg" title={editing ? t('guests.edit_guest') : t('guests.add_guest')} onClose={onClose} footer={<>
            <Button onClick={onClose} disabled={saving}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={saving} onClick={save}>{editing ? t('ui.save_changes') : t('guests.add_guest')}</Button>
        </>}>
            <form className="stack" onSubmit={(e) => { e.preventDefault(); save(); }}>
                {error && Object.keys(error.fields).length === 0 && <Alert tone="danger">{error.message}</Alert>}
                <FormSection title={t('guests.sections.personal')}>
                    <Select fieldClass="span-4" label={t('guests.fields.guest_type')} value={d.guest_type} options={options.guest_types} onChange={(e) => set('guest_type', e.target.value)} />
                    <Select fieldClass="span-2" label={t('guests.fields.title')} optional value={d.title} placeholder="—" options={options.titles} onChange={(e) => set('title', e.target.value)} />
                    <Input fieldClass="span-6" label={t('guests.fields.first_name')} required autoFocus maxLength={80} value={d.first_name} onChange={(e) => set('first_name', e.target.value)} error={err('first_name')} />
                    <Input fieldClass="span-6" label={t('guests.fields.last_name')} maxLength={80} value={d.last_name} onChange={(e) => set('last_name', e.target.value)} error={err('last_name')} />
                    <Select fieldClass="span-6" label={t('guests.fields.nationality_iso2')} value={d.nationality_iso2} placeholder="—" options={options.countries} onChange={(e) => set('nationality_iso2', e.target.value)} error={err('nationality_iso2')} />
                    <Input fieldClass="span-6" type="date" label={t('guests.fields.date_of_birth')} optional value={d.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} error={err('date_of_birth')} />
                    <div className="field span-6"><span className="field-label">&nbsp;</span><Checkbox checked={d.is_vip} onChange={(e) => set('is_vip', e.target.checked)} label={t('guests.fields.is_vip')} /></div>
                </FormSection>
                <FormSection title={t('guests.sections.contact')}>
                    <Input fieldClass="span-6" type="email" icon="mail" label={t('guests.fields.email')} maxLength={190} value={d.email} onChange={(e) => set('email', e.target.value)} error={err('email')} />
                    <Input fieldClass="span-6" type="tel" icon="phone" label={t('guests.fields.phone')} maxLength={25} placeholder="+44 7700 900123" value={d.phone} onChange={(e) => set('phone', e.target.value)} error={err('phone')} />
                    <Input fieldClass="span-12" label={t('guests.fields.address_line1')} optional maxLength={190} value={d.address_line1} onChange={(e) => set('address_line1', e.target.value)} />
                    <Input fieldClass="span-4" label={t('guests.fields.city')} optional maxLength={100} value={d.city} onChange={(e) => set('city', e.target.value)} />
                    <Input fieldClass="span-4" label={t('guests.fields.postcode')} optional maxLength={20} value={d.postcode} onChange={(e) => set('postcode', e.target.value)} />
                    <Select fieldClass="span-4" label={t('guests.fields.country_iso2')} optional value={d.country_iso2} placeholder="—" options={options.countries} onChange={(e) => set('country_iso2', e.target.value)} />
                    <div className="field span-12"><Checkbox checked={d.marketing_consent} onChange={(e) => set('marketing_consent', e.target.checked)} label={t('guests.fields.marketing_consent')} /></div>
                </FormSection>
                <FormSection title={t('guests.sections.identity')}>
                    <Select fieldClass="span-4" label={t('guests.fields.id_type')} value={d.id_type} placeholder="—" options={options.id_types} onChange={(e) => set('id_type', e.target.value)} />
                    <Input fieldClass="span-4" label={t('guests.fields.id_number')} optional maxLength={40} value={d.id_number} placeholder={guest?.id_number ?? ''} onChange={(e) => set('id_number', e.target.value)} error={err('id_number')} />
                    <Input fieldClass="span-4" type="date" label={t('guests.fields.id_expiry')} optional value={d.id_expiry} onChange={(e) => set('id_expiry', e.target.value)} />
                </FormSection>
                <FormSection title={t('guests.sections.company')}>
                    <Input fieldClass="span-6" label={t('guests.fields.company_name')} optional maxLength={190} value={d.company_name} onChange={(e) => set('company_name', e.target.value)} />
                    <Input fieldClass="span-6" label={t('guests.fields.company_tax_no')} optional maxLength={30} value={d.company_tax_no} onChange={(e) => set('company_tax_no', e.target.value)} />
                </FormSection>
                <FormSection title={t('guests.sections.other')}>
                    <Textarea fieldClass="span-6" label={t('guests.fields.preferences')} optional rows={3} maxLength={1000} value={d.preferences} onChange={(e) => set('preferences', e.target.value)} />
                    <Textarea fieldClass="span-6" label={t('guests.fields.notes')} optional rows={3} maxLength={1000} value={d.notes} onChange={(e) => set('notes', e.target.value)} />
                </FormSection>
                <button type="submit" hidden />
            </form>
        </Drawer>
    );
}
