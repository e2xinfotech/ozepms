import { useState } from 'react';
import { Button, Card, Input, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

export interface AgeBands { infant: { min: number; max: number }; child: { min: number; max: number } }

/** Settings → who counts as an infant, a child or an adult (used by prices, bookings and the booking engine). */
export function AgeBandsCard({ initial, disabled }: { initial: AgeBands; disabled: boolean }) {
    const [saved, setSaved] = useState(initial);
    const [infantMax, setInfantMax] = useState(String(initial.infant.max));
    const [childMax, setChildMax] = useState(String(initial.child.max));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const i = Number(infantMax) || 0;
    const c = Number(childMax) || 0;
    const dirty = i !== saved.infant.max || c !== saved.child.max;

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.put<{ message: string; age_bands: AgeBands }>(propertyApiUrl('/settings/age-bands'), { infant_max: i, child_max: c });
            setSaved(res.age_bands);
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card title={t('property.age_bands.title')}>
            <div className="form-grid">
                <p className="span-12 muted text-sm">{t('property.age_bands.description')}</p>
                <Input fieldClass="span-4" type="number" min={0} max={5} label={t('property.age_bands.infant_max')} disabled={disabled} value={infantMax}
                    onChange={(e) => setInfantMax(e.target.value)} error={error?.field('infant_max')} hint={t('property.age_bands.infant_hint', { max: i })} />
                <Input fieldClass="span-4" type="number" min={i + 1} max={17} label={t('property.age_bands.child_max')} disabled={disabled} value={childMax}
                    onChange={(e) => setChildMax(e.target.value)} error={error?.field('child_max')} hint={t('property.age_bands.child_hint', { min: i + 1, max: c })} />
                <div className="field span-4">
                    <span className="field-label">{t('property.age_bands.adults')}</span>
                    <div className="control readonly">{t('property.age_bands.adult_from', { age: c + 1 })}</div>
                </div>
                {!disabled && <div className="span-12 row" style={{ justifyContent: 'flex-end' }}>
                    <Button variant="primary" icon="save" loading={busy} disabled={!dirty} onClick={save}>{t('ui.save_changes')}</Button>
                </div>}
            </div>
        </Card>
    );
}
