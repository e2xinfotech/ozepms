import { useState } from 'react';
import { Alert, Badge, Button, Input, Modal, Select, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from '../../_accommodation/shared';

export interface PolicyChoice { value: string; label: string; refundable: boolean }
export interface CreatedRatePlan { id: string; name: string; code: string; is_active: boolean }

/**
 * Creates a rate plan from inside the room type form, so the room type set-up does not have to be left.
 * Prices are entered afterwards in the form's rate plan table, where the new plan is already ticked.
 */
export function QuickRatePlanModal({ mealPlans, policies, first, onClose, onCreated }: {
    mealPlans: Option[]; policies: PolicyChoice[]; first: boolean; onClose: () => void; onCreated: (plan: CreatedRatePlan) => void;
}) {
    const [form, setForm] = useState({
        name: first ? t('rooms.quick_rate_plan.default_name') : '', code: first ? 'BAR' : '',
        meal_plan: mealPlans.find((m) => m.value === 'RO')?.value ?? mealPlans[0]?.value ?? '',
        cancellation_policy: policies[0]?.value ?? '',
    });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
    const policy = policies.find((p) => p.value === form.cancellation_policy);

    const save = async () => {
        setBusy(true);
        setError(null);
        // The form shows its own message (with the next step), so the server's one is not repeated.
        const res = await act(async () => {
            const r = await http.post<{ message: string; rate_plan: CreatedRatePlan }>(propertyApiUrl('/rate-plans'), { ...form, is_active: true, is_default: first });
            return { message: undefined as string | undefined, rate_plan: r.rate_plan };
        }, setError);
        setBusy(false);
        if (res) onCreated(res.rate_plan);
    };

    return (
        <Modal open title={t('rooms.quick_rate_plan.title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="plus" loading={busy} disabled={policies.length === 0} onClick={save}>{t('rooms.quick_rate_plan.save')}</Button>
        </>}>
            <div className="form-grid">
                <p className="span-12 muted text-sm">{t('rooms.quick_rate_plan.intro')}</p>
                {policies.length === 0 && <div className="span-12"><Alert tone="warn">{t('rooms.quick_rate_plan.no_policy')}</Alert></div>}
                <Input fieldClass="span-8" label={t('rates.fields.name')} required autoFocus value={form.name} maxLength={120}
                    onChange={(e) => set('name', e.target.value)} error={fieldError(error, 'name')} />
                <Input fieldClass="span-4" label={t('rates.fields.code')} required value={form.code} maxLength={20}
                    onChange={(e) => set('code', e.target.value.toUpperCase())} error={fieldError(error, 'code')} />
                <Select fieldClass="span-12" label={t('rates.fields.meal_plan')} required value={form.meal_plan} options={mealPlans}
                    onChange={(e) => set('meal_plan', e.target.value)} error={fieldError(error, 'meal_plan')} />
                <Select fieldClass="span-12" label={t('rates.fields.policy')} required value={form.cancellation_policy}
                    options={policies.map((p) => ({ value: p.value, label: p.label }))}
                    onChange={(e) => set('cancellation_policy', e.target.value)} error={fieldError(error, 'cancellation_policy')} />
                {policy && <div className="span-12"><Badge size="sm" status={policy.refundable ? 'flexible' : 'non_refundable'}>
                    {policy.refundable ? t('rates.policy.refundable') : t('rates.policy.non_refundable')}</Badge></div>}
            </div>
        </Modal>
    );
}
