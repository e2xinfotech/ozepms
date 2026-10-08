import { useState } from 'react';
import { Alert, Badge, Button, Card, ConfirmDialog, FormSection, Input, KeyValue, LinkButton, PageHeader, Select, toast } from '@/components/ui';
import { PropertyForm, propertyPayload, propertyValues } from '@/components/property/PropertyForm';
import { PropertyMedia } from '@/components/property/PropertyMedia';
import type { PlanOption, PropertyDetail, PropertyLookups } from '@/components/property/types';
import { ImpersonateDialog } from '@/components/platform/ImpersonateDialog';
import { createPage } from '@/lib/boot';
import { date } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Props { property: PropertyDetail; lookups: PropertyLookups; plans: PlanOption[]; can_manage_subscription: boolean }

function today(offsetDays = 0): string {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Super Admin → edit a property, its status and its subscription. */
function EditPropertyPage({ property, lookups, plans, can_manage_subscription }: Props) {
    const [values, setValues] = useState(() => propertyValues(property));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const [sub, setSub] = useState({ plan_id: String(property.plan_id ?? plans[0]?.value ?? ''), starts_on: today(), ends_on: today(365), price: '', notes: '' });
    const [subBusy, setSubBusy] = useState(false);
    const [subError, setSubError] = useState<ApiError | null>(null);
    const [statusTarget, setStatusTarget] = useState<string | null>(null);
    const [owner, setOwner] = useState({ name: '', email: '' });
    const [ownerBusy, setOwnerBusy] = useState(false);
    const [ownerError, setOwnerError] = useState<ApiError | null>(null);
    const [ownerConfirm, setOwnerConfirm] = useState(false);
    const [asOwner, setAsOwner] = useState(false);

    const changeOwner = async () => {
        setOwnerBusy(true);
        setOwnerError(null);
        try {
            const res = await http.post<{ message: string }>(`/web-api/admin/properties/${property.code}/owner`, owner);
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            setOwnerError(e as ApiError);
            setOwnerBusy(false);
            setOwnerConfirm(false);
        }
    };

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.put<{ message: string }>(`/web-api/admin/properties/${property.code}`, propertyPayload(values));
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };

    const assign = async () => {
        setSubBusy(true);
        setSubError(null);
        try {
            const res = await http.post<{ message: string }>(`/web-api/admin/properties/${property.code}/subscription`, {
                plan_id: Number(sub.plan_id), starts_on: sub.starts_on, ends_on: sub.ends_on, price: sub.price || null, notes: sub.notes || null,
            });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            setSubError(e as ApiError);
            setSubBusy(false);
        }
    };

    const changeStatus = async () => {
        if (!statusTarget) return;
        try {
            const res = await http.post<{ message: string }>(`/web-api/admin/properties/${property.code}/status`, { status: statusTarget });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setStatusTarget(null);
        }
    };

    return (
        <div className="content">
            <PageHeader back={`/admin/properties?selected=${property.code}`}
                title={<span className="row" style={{ gap: 12 }}>{property.name}<Badge status={property.status} /></span>}
                description={`${property.code} · ${property.location}`}
                actions={<>
                    <LinkButton icon="arrow-right" href={`/p/${property.code}/dashboard`}>{t('property.open_property')}</LinkButton>
                    {property.status !== 'suspended' && <Button variant="outline" icon="ban" onClick={() => setStatusTarget('suspended')}>{t('property.suspend')}</Button>}
                    {property.status === 'active'
                        ? <Button variant="danger-soft" icon="pause" onClick={() => setStatusTarget('inactive')}>{t('property.deactivate')}</Button>
                        : <Button variant="outline" icon="check-circle" onClick={() => setStatusTarget('active')}>{t('property.activate')}</Button>}
                </>} />

            {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}
            <PropertyForm value={values} onChange={setValues} lookups={lookups} error={error}
                media={<PropertyMedia endpoint={`/web-api/admin/properties/${property.code}/media`} logo={property.logo} image={property.image} />} />

            {property.owner?.can_impersonate && (
                <Card title={t('impersonation.log_in_as')}>
                    <div className="row" style={{ justifyContent: 'space-between' }}>
                        <span>{property.owner.name} ({property.owner.email})</span>
                        <Button variant="outline" icon="log-in" onClick={() => setAsOwner(true)}>{t('impersonation.log_in_as')}</Button>
                    </div>
                    <ImpersonateDialog userId={property.owner.id} name={property.owner.name} open={asOwner} onClose={() => setAsOwner(false)} />
                </Card>
            )}

            <Card title={t('property.sections.subscription')}>
                <KeyValue items={[
                    { label: t('subscription.plan'), value: property.subscription.plan },
                    { label: t('ui.status_label'), value: <Badge status={property.subscription.status} /> },
                    { label: t('subscription.ends_on'), value: date(property.subscription.ends_on) },
                    { label: t('property.sections.owner'), value: property.owner ? `${property.owner.name} (${property.owner.email})` : null },
                ]} />
            </Card>

            <FormSection title={t('property.change_owner')} description={t('property.change_owner_sub')}
                actions={<Button variant="outline" icon="user-check" disabled={!owner.name || !owner.email} onClick={() => setOwnerConfirm(true)}>{t('property.change_owner')}</Button>}>
                <Input fieldClass="span-6" label={t('property.owner_name')} required value={owner.name} maxLength={120} onChange={(e) => setOwner({ ...owner, name: e.target.value })} error={ownerError?.field('name')} />
                <Input fieldClass="span-6" label={t('property.owner_email')} required type="email" icon="mail" value={owner.email} onChange={(e) => setOwner({ ...owner, email: e.target.value })} error={ownerError?.field('email')} />
            </FormSection>

            {can_manage_subscription && (
                <FormSection title={t('subscription.assign')} description={t('subscription.assign_sub')}
                    actions={<Button variant="outline" icon="credit-card" loading={subBusy} onClick={assign}>{t('subscription.assign')}</Button>}>
                    <Select fieldClass="span-4" label={t('subscription.plan')} value={sub.plan_id} options={plans} onChange={(e) => setSub({ ...sub, plan_id: e.target.value })} error={subError?.field('plan_id')} />
                    <Input fieldClass="span-2" label={t('subscription.starts_on')} type="date" value={sub.starts_on} onChange={(e) => setSub({ ...sub, starts_on: e.target.value })} error={subError?.field('starts_on')} />
                    <Input fieldClass="span-2" label={t('subscription.ends_on')} type="date" value={sub.ends_on} onChange={(e) => setSub({ ...sub, ends_on: e.target.value })} error={subError?.field('ends_on')} />
                    <Input fieldClass="span-4" label={t('subscription.agreed_price')} optional inputMode="decimal" value={sub.price} onChange={(e) => setSub({ ...sub, price: e.target.value })} error={subError?.field('price')} />
                    <Input fieldClass="span-12" label={t('subscription.notes')} optional value={sub.notes} onChange={(e) => setSub({ ...sub, notes: e.target.value })} error={subError?.field('notes')} />
                </FormSection>
            )}

            <div className="form-footer">
                <span className="spacer" />
                <LinkButton href="/admin/properties">{t('ui.cancel')}</LinkButton>
                <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save_changes')}</Button>
            </div>

            <ConfirmDialog open={ownerConfirm} busy={ownerBusy} title={t('property.change_owner')}
                message={t('property.change_owner_confirm', { name: owner.name, email: owner.email })}
                onConfirm={changeOwner} onClose={() => setOwnerConfirm(false)} />
            <ConfirmDialog open={!!statusTarget} danger={statusTarget !== 'active'}
                title={t(statusTarget === 'inactive' ? 'property.deactivate' : statusTarget === 'suspended' ? 'property.suspend' : 'property.activate')}
                message={t(statusTarget === 'inactive' ? 'property.deactivate_confirm' : statusTarget === 'suspended' ? 'property.suspend_confirm' : 'property.activate_confirm', { name: property.name })}
                onConfirm={changeStatus} onClose={() => setStatusTarget(null)} />
        </div>
    );
}

createPage(EditPropertyPage);
