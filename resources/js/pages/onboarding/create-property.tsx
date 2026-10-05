import { useState } from 'react';
import { Alert, Button, PageHeader, toast } from '@/components/ui';
import { emptyPropertyValues, PropertyForm, propertyPayload } from '@/components/property/PropertyForm';
import type { PropertyLookups } from '@/components/property/types';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

/** First property of a signed-in user; they become its owner on a trial plan. */
function CreatePropertyPage({ lookups }: { lookups: PropertyLookups }) {
    const [values, setValues] = useState(() => emptyPropertyValues(lookups));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ redirect: string }>('/web-api/properties', propertyPayload(values));
            window.location.href = res.redirect;
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setBusy(false);
        }
    };

    return (
        <div className="content">
            <PageHeader title={t('property.create_title')} description={t('property.create_sub')} />
            {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}
            <PropertyForm value={values} onChange={setValues} lookups={lookups} error={error} autoRegional />
            <div className="form-footer">
                <span className="spacer" />
                <Button variant="primary" icon="check" loading={busy} onClick={save}>{t('property.create_button')}</Button>
            </div>
        </div>
    );
}

createPage(CreatePropertyPage);
