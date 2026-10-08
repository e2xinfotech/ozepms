import { useState } from 'react';
import { Alert, Badge, Button, DataTable, Drawer, Field, FormSection, Input, PageHeader, Pagination, PillTabs, Select, Textarea, Toggle, toast, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { money, number } from '@/lib/format';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Plan {
    id: number; code: string; name: string; description: string | null; price: string; currency_code: string; billing_cycle: string;
    trial_days: number; grace_days: number; max_room_types: number | null; max_units: number | null; max_users: number | null;
    features: Record<string, boolean>; is_active: boolean; approval_status: string; properties: number;
}
interface Props { rows: Plan[]; meta: PageMeta; counts: { all: number; active: number; inactive: number }; filters: { status: string }; features: string[]; currencies: Option[]; can_approve: boolean }

const limit = (v: number | null) => (v === null ? t('subscription.unlimited') : number(v));
const str = (v: number | null) => (v === null ? '' : String(v));

function PlanDrawer({ plan, features, currencies, canApprove, onClose }: { plan: Plan | null; features: string[]; currencies: Option[]; canApprove: boolean; onClose: () => void }) {
    const locked = !!plan && plan.approval_status === 'approved' && !canApprove;
    const [d, setD] = useState({
        code: plan?.code ?? '', name: plan?.name ?? '', description: plan?.description ?? '', price: plan?.price ?? '0.00',
        currency_code: plan?.currency_code ?? String(currencies[0]?.value ?? 'INR'), billing_cycle: plan?.billing_cycle ?? 'monthly',
        trial_days: String(plan?.trial_days ?? 14), grace_days: String(plan?.grace_days ?? 7),
        max_room_types: str(plan?.max_room_types ?? null), max_units: str(plan?.max_units ?? null), max_users: str(plan?.max_users ?? null),
        features: plan?.features ?? Object.fromEntries(features.map((f) => [f, false])), is_active: plan?.is_active ?? true,
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const set = (k: keyof typeof d, v: unknown) => setD((x) => ({ ...x, [k]: v }));
    const intOrNull = (v: string) => (v === '' ? null : Number(v));

    const save = async () => {
        setBusy(true);
        setError(null);
        const body: Record<string, unknown> = {
            name: d.name, description: d.description || null, price: d.price, currency_code: d.currency_code, billing_cycle: d.billing_cycle,
            trial_days: Number(d.trial_days), grace_days: Number(d.grace_days),
            max_room_types: intOrNull(d.max_room_types), max_units: intOrNull(d.max_units), max_users: intOrNull(d.max_users),
            features: d.features, is_active: d.is_active,
        };
        try {
            const res = plan
                ? await http.put<{ message: string }>(`/web-api/admin/plans/${plan.code}`, body)
                : await http.post<{ message: string }>('/web-api/admin/plans', { ...body, code: d.code });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
        }
    };

    return (
        <Drawer open title={plan ? t('subscription.edit_plan') : t('subscription.add_plan')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} disabled={locked} onClick={save}>{t('ui.save_changes')}</Button>
        </>}>
            {locked && <Alert tone="warn">{t('approvals.plan_locked')}</Alert>}
            {!plan && !canApprove && <Alert tone="info">{t('approvals.plan_submitted')}</Alert>}
            {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
            <FormSection title={t('subscription.section_plan')}>
                <Input fieldClass="span-4" label={t('subscription.code')} required disabled={!!plan} value={d.code} onChange={(e) => set('code', e.target.value)} error={error?.field('code')} hint={plan ? undefined : t('subscription.code_hint')} />
                <Input fieldClass="span-8" label={t('subscription.name')} required value={d.name} onChange={(e) => set('name', e.target.value)} error={error?.field('name')} />
                <Textarea fieldClass="span-12" label={t('subscription.description')} optional rows={2} value={d.description} onChange={(e) => set('description', e.target.value)} error={error?.field('description')} />
            </FormSection>
            <FormSection title={t('subscription.section_pricing')}>
                <Input fieldClass="span-4" label={t('subscription.price')} required inputMode="decimal" value={d.price} onChange={(e) => set('price', e.target.value)} error={error?.field('price')} />
                <Select fieldClass="span-4" label={t('subscription.currency')} value={d.currency_code} options={currencies} onChange={(e) => set('currency_code', e.target.value)} error={error?.field('currency_code')} />
                <Select fieldClass="span-4" label={t('subscription.billing_cycle')} value={d.billing_cycle} options={['monthly', 'quarterly', 'yearly'].map((c) => ({ value: c, label: t(`subscription.cycles.${c}`) }))} onChange={(e) => set('billing_cycle', e.target.value)} />
                <Input fieldClass="span-6" label={t('subscription.trial_days')} type="number" min={0} value={d.trial_days} onChange={(e) => set('trial_days', e.target.value)} error={error?.field('trial_days')} />
                <Input fieldClass="span-6" label={t('subscription.grace_days')} type="number" min={0} value={d.grace_days} onChange={(e) => set('grace_days', e.target.value)} error={error?.field('grace_days')} />
            </FormSection>
            <FormSection title={t('subscription.section_limits')} description={t('subscription.leave_empty_unlimited')}>
                <Input fieldClass="span-4" label={t('subscription.max_room_types')} optional type="number" min={1} value={d.max_room_types} onChange={(e) => set('max_room_types', e.target.value)} error={error?.field('max_room_types')} />
                <Input fieldClass="span-4" label={t('subscription.max_units')} optional type="number" min={1} value={d.max_units} onChange={(e) => set('max_units', e.target.value)} error={error?.field('max_units')} />
                <Input fieldClass="span-4" label={t('subscription.max_users')} optional type="number" min={1} value={d.max_users} onChange={(e) => set('max_users', e.target.value)} error={error?.field('max_users')} />
            </FormSection>
            <FormSection title={t('subscription.features')}>
                {features.map((f) => (
                    <Field key={f} className="span-6"><Toggle label={t(`subscription.feature.${f}`)} checked={!!d.features[f]} onChange={(v) => set('features', { ...d.features, [f]: v })} /></Field>
                ))}
                <Field className="span-12" label={t('ui.status_label')}><Toggle label={d.is_active ? t('ui.status.active') : t('ui.status.inactive')} checked={d.is_active} onChange={(v) => set('is_active', v)} /></Field>
            </FormSection>
        </Drawer>
    );
}

/** Super Admin → subscription plans. */
function PlansPage({ rows, meta, counts, filters, features, currencies, can_approve }: Props) {
    const [editing, setEditing] = useState<{ plan: Plan | null } | null>(null);
    const columns: Column<Plan>[] = [
        { key: 'name', header: t('subscription.name'), render: (p) => <div><div className="cell-main">{p.name} {p.approval_status !== 'approved' && <Badge size="sm" status={p.approval_status === 'pending' ? 'pending_approval' : 'rejected'} />}</div><div className="cell-sub">{p.code}</div></div> },
        { key: 'price', header: t('subscription.price'), align: 'right', render: (p) => <><span className="nowrap">{money(p.price, p.currency_code)}</span><div className="cell-sub">{t(`subscription.cycles.${p.billing_cycle}`)}</div></> },
        { key: 'trial', header: t('subscription.trial_days'), align: 'right', render: (p) => number(p.trial_days) },
        { key: 'rt', header: t('subscription.max_room_types'), align: 'right', render: (p) => limit(p.max_room_types) },
        { key: 'units', header: t('subscription.max_units'), align: 'right', render: (p) => limit(p.max_units) },
        { key: 'users', header: t('subscription.max_users'), align: 'right', render: (p) => limit(p.max_users) },
        { key: 'features', header: t('subscription.features'), render: (p) => <div className="perm-tags">{features.filter((f) => p.features[f]).map((f) => <Badge key={f} size="sm" tone="blue">{t(`subscription.feature.${f}`)}</Badge>)}</div> },
        { key: 'props', header: t('subscription.properties_on_plan'), align: 'right', render: (p) => number(p.properties) },
        { key: 'status', header: t('ui.status_label'), render: (p) => <Badge status={p.is_active ? 'active' : 'inactive'} /> },
        { key: 'actions', header: t('ui.actions'), className: 'col-actions', render: (p) => <Button size="sm" variant="outline" icon="pencil" onClick={(e) => { e.stopPropagation(); setEditing({ plan: p }); }}>{t('ui.edit')}</Button> },
    ];

    return (
        <div className="content">
            <PageHeader title={t('subscription.plans')} description={t('subscription.plans_sub')}
                actions={<Button variant="primary" icon="plus" onClick={() => setEditing({ plan: null })}>{t('subscription.add_plan')}</Button>} />
            <PillTabs active={filters.status || 'all'} onChange={(k) => navigateWithQuery({ status: k === 'all' ? null : k })} items={[
                { key: 'all', label: t('ui.all'), count: counts.all },
                { key: 'active', label: t('ui.status.active'), count: counts.active },
                { key: 'inactive', label: t('ui.status.inactive'), count: counts.inactive },
            ]} />
            <DataTable columns={columns} rows={rows} rowKey={(p) => p.code} onRowClick={(p) => setEditing({ plan: p })} />
            <Pagination meta={meta} label={t('subscription.plans_lc')} />
            {editing && <PlanDrawer plan={editing.plan} features={features} currencies={currencies} canApprove={can_approve} onClose={() => setEditing(null)} />}
        </div>
    );
}

createPage(PlansPage);
