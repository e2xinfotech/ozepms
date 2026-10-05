import { useState } from 'react';
import { Alert, Button, Input, Modal, Select, Textarea, Toggle } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from '../../_accommodation/shared';
import type { PolicyRule } from './types';

export interface PolicyOption { value: string; label: string; refundable: boolean; description: string | null; rules: PolicyRule[] }

const CHARGES = ['none', 'first_night', 'nights', 'percent', 'fixed', 'full'];

/** Create or edit a cancellation policy with its charge windows. */
export function PolicyModal({ policy, onClose, onSaved }: { policy: PolicyOption | null; onClose: () => void; onSaved: (code: string, list: PolicyOption[]) => void }) {
    const [form, setForm] = useState({
        code: policy?.value ?? '', name: policy?.label ?? '', is_refundable: policy?.refundable ?? true, description: policy?.description ?? '',
    });
    const [rules, setRules] = useState<PolicyRule[]>(policy?.rules ?? [
        { applies_to: 'cancellation', hours_before_arrival: 24, charge_type: 'first_night', charge_value: null },
        { applies_to: 'no_show', hours_before_arrival: 0, charge_type: 'first_night', charge_value: null },
    ]);
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const setRule = (i: number, patch: Partial<PolicyRule>) => setRules((l) => l.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const save = async () => {
        setBusy(true);
        setError(null);
        const body = { ...form, description: form.description || null, rules: rules.map((r) => ({ ...r, charge_value: ['nights', 'percent', 'fixed'].includes(r.charge_type) ? r.charge_value : null })) };
        const res = await act(() => policy
            ? http.put<{ message: string; policy: string; policies: PolicyOption[] }>(propertyApiUrl(`/cancellation-policies/${policy.value}`), body)
            : http.post<{ message: string; policy: string; policies: PolicyOption[] }>(propertyApiUrl('/cancellation-policies'), body), setError);
        setBusy(false);
        if (res) onSaved(res.policy, res.policies);
    };

    const ruleError = error ? Object.entries(error.fields).find(([k]) => k.startsWith('rules'))?.[1]?.[0] : undefined;

    return (
        <Modal open size="lg" title={policy ? t('rates.policy.edit') : t('rates.policy.new')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save')}</Button>
        </>}>
            <div className="stack">
                <div className="form-grid">
                    <Input fieldClass="span-4" label={t('rates.fields.policy_code')} required disabled={!!policy} value={form.code} maxLength={30}
                        onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={fieldError(error, 'code')} />
                    <Input fieldClass="span-8" label={t('rates.fields.policy_name')} required value={form.name} maxLength={120}
                        onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldError(error, 'name')} />
                    <Textarea fieldClass="span-12" label={t('rates.fields.description')} optional rows={2} value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    <div className="field span-12">
                        <span className="field-label">{t('rates.fields.refundable')}</span>
                        <Toggle checked={form.is_refundable} onChange={(v) => setForm({ ...form, is_refundable: v })} label={form.is_refundable ? t('rates.policy.refundable') : t('rates.policy.non_refundable')} />
                    </div>
                </div>
                <h3>{t('rates.policy.rules')}</h3>
                {ruleError && <Alert tone="danger">{ruleError}</Alert>}
                <div className="edit-rows">
                    {rules.map((r, i) => (
                        <div key={i} className="inline-fields">
                            <Select label={i === 0 ? t('rates.fields.applies_to') : undefined} aria-label={t('rates.fields.applies_to')} value={r.applies_to}
                                options={[{ value: 'cancellation', label: t('rates.policy.cancellation') }, { value: 'no_show', label: t('rates.policy.no_show') }]}
                                onChange={(e) => setRule(i, { applies_to: e.target.value, hours_before_arrival: e.target.value === 'no_show' ? 0 : r.hours_before_arrival })} />
                            <Input label={i === 0 ? t('rates.fields.hours_before') : undefined} aria-label={t('rates.fields.hours_before')} type="number" min={0} max={8760}
                                disabled={r.applies_to === 'no_show'} value={r.hours_before_arrival} onChange={(e) => setRule(i, { hours_before_arrival: Number(e.target.value) })} />
                            <Select label={i === 0 ? t('rates.fields.charge') : undefined} aria-label={t('rates.fields.charge')} value={r.charge_type}
                                options={CHARGES.map((c) => ({ value: c, label: t(`rates.policy.charge_types.${c}`) }))} onChange={(e) => setRule(i, { charge_type: e.target.value })} />
                            <Input label={i === 0 ? t('rates.fields.charge_value') : undefined} aria-label={t('rates.fields.charge_value')} type="number" min={0} step="0.01"
                                disabled={!['nights', 'percent', 'fixed'].includes(r.charge_type)} value={r.charge_value ?? ''} onChange={(e) => setRule(i, { charge_value: e.target.value })} />
                            <div className="field">
                                {i === 0 && <span className="field-label">&nbsp;</span>}
                                <Button variant="ghost" icon="trash" title={t('ui.delete')} aria-label={t('ui.delete')} onClick={() => setRules((l) => l.filter((_, j) => j !== i))} />
                            </div>
                        </div>
                    ))}
                    <div><Button size="sm" variant="outline" icon="plus" onClick={() => setRules((l) => [...l, { applies_to: 'cancellation', hours_before_arrival: 48, charge_type: 'percent', charge_value: '50' }])}>{t('rates.policy.add_rule')}</Button></div>
                </div>
            </div>
        </Modal>
    );
}
