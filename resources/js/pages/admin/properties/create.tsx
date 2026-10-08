import { useState } from 'react';
import { Alert, Button, FormSection, LinkButton, PageHeader, Select, toast } from '@/components/ui';
import { emptyPropertyValues, PropertyForm, propertyPayload } from '@/components/property/PropertyForm';
import type { PlanOption, PropertyLookups } from '@/components/property/types';
import { createPage } from '@/lib/boot';
import { money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Props { lookups: PropertyLookups; plans: PlanOption[]; owners: { value: string; label: string }[]; owner_id: string }

/** Super Admin → register a property with its owner and plan. */
function CreatePropertyPage({ lookups, plans, owners, owner_id }: Props) {
    const [values, setValues] = useState(() => emptyPropertyValues(lookups));
    const [ownerId, setOwnerId] = useState(owners.some((o) => o.value === owner_id) ? owner_id : '');
    const [planId, setPlanId] = useState(String(plans[0]?.value ?? ''));
    const [status, setStatus] = useState('onboarding');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ redirect: string }>('/web-api/admin/properties', {
                ...propertyPayload(values),
                status,
                plan_id: planId ? Number(planId) : null,
                owner_id: ownerId,
            });
            window.location.href = res.redirect;
        } catch (e) {
            const err = e as ApiError;
            setError(err);
            toast.error(err.message, err.ref);
            setBusy(false);
        }
    };

    return (
        <div className="content">
            <PageHeader back="/admin/properties" title={t('admin.add_property')} description={t('admin.add_property_sub')} />
            {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}
            <PropertyForm value={values} onChange={setValues} lookups={lookups} error={error} autoRegional />

            <FormSection title={t('property.sections.owner')} description={t('property.choose_owner_hint')}
                actions={<LinkButton variant="outline" icon="plus" href="/admin/users?new=owner">{t('property.add_owner')}</LinkButton>}>
                <Select fieldClass="span-12" label={t('property.owner')} required value={ownerId} placeholder={t('property.choose_owner')} options={owners}
                    onChange={(e) => setOwnerId(e.target.value)} error={error?.field('owner_id') ?? error?.field('owner')} />
                {owners.length === 0 && <div className="span-12"><Alert tone="warn">{t('property.no_owners')}</Alert></div>}
            </FormSection>

            <FormSection title={t('property.sections.subscription')} description={t('property.plan_hint')}>
                <Select fieldClass="span-6" label={t('subscription.plan')} value={planId} placeholder={t('ui.none')} error={error?.field('plan_id')}
                    options={plans.map((p) => ({ value: p.value, label: `${p.label} — ${money(p.price, p.currency)} / ${t(`subscription.cycles.${p.cycle}`)}` }))}
                    onChange={(e) => setPlanId(e.target.value)} />
                <Select fieldClass="span-6" label={t('ui.status_label')} value={status} error={error?.field('status')}
                    options={['onboarding', 'active'].map((s) => ({ value: s, label: t(`ui.status.${s}`) }))} onChange={(e) => setStatus(e.target.value)} />
            </FormSection>

            <div className="form-footer">
                <span className="spacer" />
                <LinkButton href="/admin/properties">{t('ui.cancel')}</LinkButton>
                <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('admin.create_property')}</Button>
            </div>
        </div>
    );
}

createPage(CreatePropertyPage);
