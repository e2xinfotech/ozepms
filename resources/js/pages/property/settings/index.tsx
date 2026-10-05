import { useState } from 'react';
import { Alert, Button, PageHeader, toast } from '@/components/ui';
import { PropertyForm, propertyPayload, propertyValues } from '@/components/property/PropertyForm';
import type { PropertyDetail, PropertyLookups } from '@/components/property/types';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

interface Props { property: PropertyDetail; lookups: PropertyLookups; can_update: boolean }

/** Property Configuration of the current property. */
function SettingsPage({ property, lookups, can_update }: Props) {
    const initial = propertyValues(property);
    const [values, setValues] = useState(initial);
    const [saved, setSaved] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const dirty = JSON.stringify(values) !== JSON.stringify(saved);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.put<{ message: string; property: PropertyDetail }>(propertyApiUrl('/settings'), propertyPayload(values));
            const next = propertyValues(res.property);
            setValues(next);
            setSaved(next);
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="content">
            <PageHeader title={t('property.settings_title')} description={t('property.settings_sub')} />
            {!can_update && <Alert tone="info">{t('property.settings_read_only')}</Alert>}
            {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}
            <PropertyForm value={values} onChange={setValues} lookups={lookups} error={error} disabled={!can_update} />
            {can_update && (
                <div className="form-footer">
                    {dirty && <span className="muted text-sm">{t('ui.unsaved_changes')}</span>}
                    <span className="spacer" />
                    <Button disabled={!dirty || busy} onClick={() => { setValues(saved); setError(null); }}>{t('ui.reset')}</Button>
                    <Button variant="primary" icon="save" loading={busy} disabled={!dirty} onClick={save}>{t('ui.save_changes')}</Button>
                </div>
            )}
        </div>
    );
}

createPage(SettingsPage);
