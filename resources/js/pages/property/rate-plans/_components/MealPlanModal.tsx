import { useState } from 'react';
import { Button, Checkbox, Input, Modal, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from '../../_accommodation/shared';

/** Adds a property's own meal plan (spec: Room Only … All Inclusive + Custom). */
export function MealPlanModal({ onClose, onSaved }: { onClose: () => void; onSaved: (code: string, list: Option[]) => void }) {
    const [form, setForm] = useState({ code: '', name: '', includes_breakfast: false, includes_lunch: false, includes_dinner: false, is_all_inclusive: false });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.post<{ message: string; meal_plan: string; meal_plans: Option[] }>(propertyApiUrl('/meal-plans'), form), setError);
        setBusy(false);
        if (res) onSaved(res.meal_plan, res.meal_plans);
    };

    return (
        <Modal open title={t('rates.meal_plan_custom.title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="plus" loading={busy} onClick={save}>{t('rates.meal_plan_custom.save')}</Button>
        </>}>
            <div className="form-grid">
                <p className="span-12 muted text-sm">{t('rates.meal_plan_custom.hint')}</p>
                <Input fieldClass="span-8" label={t('rates.fields.name')} required autoFocus maxLength={80} value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldError(error, 'name')} />
                <Input fieldClass="span-4" label={t('rates.fields.code')} required maxLength={20} value={form.code}
                    onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={fieldError(error, 'code')} />
                <div className="field span-12">
                    <span className="field-label">{t('rates.meal_plan_custom.includes')}</span>
                    <div className="check-grid">
                        {(['breakfast', 'lunch', 'dinner'] as const).map((m) => (
                            <Checkbox key={m} label={t(`rates.meal_plan_custom.${m}`)} disabled={form.is_all_inclusive}
                                checked={form.is_all_inclusive || form[`includes_${m}`]} onChange={(e) => setForm({ ...form, [`includes_${m}`]: e.target.checked })} />
                        ))}
                        <Checkbox label={t('rates.meal_plan_custom.all_inclusive')} checked={form.is_all_inclusive}
                            onChange={(e) => setForm({ ...form, is_all_inclusive: e.target.checked })} />
                    </div>
                </div>
            </div>
        </Modal>
    );
}
