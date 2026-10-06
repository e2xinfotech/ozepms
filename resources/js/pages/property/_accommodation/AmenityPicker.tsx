import { useMemo, useState } from 'react';
import { Button, Checkbox, Input, Select, type Option } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from './shared';

export interface AmenityOption { value: string; label: string; category: string; icon: string | null; custom?: boolean }

/**
 * Amenity checklist grouped by category, used by room type and PMS room forms.
 * Amenities live in one central list; a missing one can be added here and is saved to that list,
 * so it is immediately available on every other form of the property.
 */
export function AmenityPicker({ options, categories, selected, onChange, canAdd, inherited }: {
    options: AmenityOption[];
    categories: Option[];
    selected: Set<string>;
    onChange: (next: Set<string>) => void;
    canAdd: boolean;
    /** Codes that come from the room type (shown with a hint on room forms). */
    inherited?: Set<string>;
}) {
    const [extra, setExtra] = useState<AmenityOption[]>([]);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ name: '', category: String(categories[0]?.value ?? 'room') });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const all = useMemo(() => [...options, ...extra.filter((e) => !options.some((o) => o.value === e.value))], [options, extra]);
    const groups = useMemo(() => categories
        .map((c) => ({ ...c, items: all.filter((a) => a.category === c.value) }))
        .filter((g) => g.items.length > 0), [all, categories]);

    const toggle = (code: string) => {
        const next = new Set(selected);
        if (next.has(code)) next.delete(code); else next.add(code);
        onChange(next);
    };

    const add = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.post<{ message: string; amenity: { id: string; name: string; category: string; icon: string | null } }>(
            propertyApiUrl('/amenities'), { name: form.name.trim(), category: form.category },
        ), setError);
        setBusy(false);
        if (!res) return;
        const a = res.amenity;
        setExtra((list) => [...list, { value: a.id, label: a.name, category: a.category, icon: a.icon, custom: true }]);
        onChange(new Set([...selected, a.id]));
        setForm({ name: '', category: form.category });
        setAdding(false);
    };

    return (
        <div className="amenity-picker">
            {groups.map((g) => (
                <div key={g.value} className="amenity-group">
                    <h4>{g.label}</h4>
                    <div className="amenity-grid">
                        {g.items.map((a) => (
                            <span key={a.value} title={inherited?.has(a.value) ? t('amenities.from_room_type') : undefined}>
                                <Checkbox checked={selected.has(a.value)} onChange={() => toggle(a.value)}
                                    label={<>{a.label}{inherited && !inherited.has(a.value) && selected.has(a.value) && <span className="amenity-extra">{t('amenities.room_only')}</span>}</>} />
                            </span>
                        ))}
                    </div>
                </div>
            ))}

            {canAdd && (adding ? (
                <div className="amenity-add">
                    <div className="form-grid">
                        <Input fieldClass="span-6" label={t('amenities.fields.name')} required autoFocus maxLength={80} value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldError(error, 'name')}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void add(); } }} />
                        <Select fieldClass="span-4" label={t('amenities.fields.category')} value={form.category} options={categories}
                            onChange={(e) => setForm({ ...form, category: e.target.value })} error={fieldError(error, 'category')} />
                        <div className="span-2 amenity-add-actions">
                            <Button variant="primary" icon="plus" loading={busy} disabled={!form.name.trim()} onClick={add}>{t('ui.add')}</Button>
                        </div>
                    </div>
                    <div className="row" style={{ justifyContent: 'space-between' }}>
                        <span className="field-hint">{t('amenities.add_hint')}</span>
                        <button type="button" className="link-btn" onClick={() => { setAdding(false); setError(null); }}>{t('ui.cancel')}</button>
                    </div>
                </div>
            ) : (
                <Button size="sm" variant="outline" icon="plus" onClick={() => setAdding(true)}>{t('amenities.add_missing')}</Button>
            ))}
        </div>
    );
}
