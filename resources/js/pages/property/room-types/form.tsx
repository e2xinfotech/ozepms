import { useMemo, useState, type ChangeEvent } from 'react';
import { Alert, Button, Checkbox, ConfirmDialog, FormSection, Icon, Input, LinkButton, PageHeader, Segmented, Select, Textarea, Toggle, toast, type Option } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, errorsUnder, fieldError, MoneyInput, OccupancyEditor, price, type OccupancyRule } from '../_accommodation/shared';

interface Unit { id: string | null; name: string; floor: string | null; is_active: boolean }
interface Product {
    rate_plan: string; pricing_mode: 'manual' | 'derived'; default_price: string | null; base_price: string | null;
    parent_rate_plan: string | null; adjust_type: string | null; adjust_value: string | null; is_default: boolean; is_active: boolean;
    occupancy_rules: OccupancyRule[];
}
interface RoomType {
    id: string; code: string; name: string; category: string | null; description: string | null;
    base_adults: number; max_adults: number; max_children: number; max_infants: number; max_occupancy: number;
    extra_bed_allowed: boolean; max_extra_beds: number; size_value: string | null; size_unit: string | null;
    smoking_policy: string; view_label: string | null; is_active: boolean;
    beds: { bed_type: string; quantity: number }[]; amenities: string[]; images: { id: number; url: string; alt: string | null }[];
    units: Unit[]; active_units: number; products: Product[];
}
interface AmenityOption { value: string; label: string; category: string; icon: string | null }
interface RatePlanOption { value: string; label: string; name: string; code: string; is_active: boolean }
interface Props {
    room_type: RoomType | null;
    options: {
        categories: Option[]; bed_types: Option[]; amenities: AmenityOption[]; amenity_categories: Option[];
        rate_plans: RatePlanOption[]; age_bands: Option[];
        usage: { units: number | null; used_units: number; room_types: number | null; used_room_types: number };
    };
}

interface ProductRow {
    rate_plan_id: string; enabled: boolean; pricing_mode: 'manual' | 'derived'; default_price: string;
    parent_rate_plan_id: string; adjust_type: string; adjust_value: string; is_default: boolean; occupancy_rules: OccupancyRule[]; open: boolean;
}

/** Create / edit a room type: details, occupancy, beds, amenities, PMS rooms, rate plans and images. */
function RoomTypeForm({ room_type: rt, options }: Props) {
    const editing = rt !== null;
    const [data, setData] = useState({
        code: rt?.code ?? '', name: rt?.name ?? '', category: rt?.category ?? '', description: rt?.description ?? '',
        base_adults: rt?.base_adults ?? 2, max_adults: rt?.max_adults ?? 2, max_children: rt?.max_children ?? 0,
        max_infants: rt?.max_infants ?? 0, max_occupancy: rt?.max_occupancy ?? 2,
        extra_bed_allowed: rt?.extra_bed_allowed ?? false, max_extra_beds: rt?.max_extra_beds ?? 0,
        size_value: rt?.size_value ?? '', size_unit: rt?.size_unit ?? 'sqm', smoking_policy: rt?.smoking_policy ?? 'non_smoking',
        view_label: rt?.view_label ?? '', is_active: rt?.is_active ?? true,
    });
    const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));
    const num = (key: 'base_adults' | 'max_adults' | 'max_children' | 'max_infants' | 'max_occupancy' | 'max_extra_beds') =>
        (e: ChangeEvent<HTMLInputElement>) => set(key, Number(e.target.value));

    const [beds, setBeds] = useState(rt?.beds.map((b) => ({ ...b })) ?? [{ bed_type: 'king', quantity: 1 }]);
    const [amenities, setAmenities] = useState<Set<string>>(new Set(rt?.amenities ?? ['wifi']));
    const [unitMode, setUnitMode] = useState<'quantity' | 'names'>(editing ? 'names' : 'quantity');
    const [quantity, setQuantity] = useState(String(rt?.active_units ?? 1));
    const [floor, setFloor] = useState('');
    const [units, setUnits] = useState<Unit[]>(rt?.units ?? []);
    const [products, setProducts] = useState<ProductRow[]>(() => options.rate_plans.map((p) => {
        const existing = rt?.products.find((x) => x.rate_plan === p.value);
        return {
            rate_plan_id: p.value, enabled: existing?.is_active ?? false, pricing_mode: existing?.pricing_mode ?? 'manual',
            default_price: existing?.default_price ?? '', parent_rate_plan_id: existing?.parent_rate_plan ?? '',
            adjust_type: existing?.adjust_type ?? 'percent', adjust_value: existing?.adjust_value ? String(Number(existing.adjust_value)) : '-10',
            is_default: existing?.is_default ?? false, occupancy_rules: existing?.occupancy_rules ?? [], open: false,
        };
    }));
    const [images, setImages] = useState(rt?.images ?? []);
    const [uploading, setUploading] = useState(false);
    const [removeImage, setRemoveImage] = useState<number | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [saving, setSaving] = useState(false);

    const groupedAmenities = useMemo(() => options.amenity_categories
        .map((c) => ({ ...c, items: options.amenities.filter((a) => a.category === c.value) }))
        .filter((g) => g.items.length > 0), [options]);

    const setProduct = (i: number, patch: Partial<ProductRow>) => setProducts((list) => list.map((p, j) => (j === i ? { ...p, ...patch } : p)));
    const usage = options.usage;
    const err = (key: string) => fieldError(error, key);

    const save = async () => {
        setSaving(true);
        setError(null);
        const body: Record<string, unknown> = {
            ...data,
            category: data.category || null,
            size_value: data.size_value === '' ? null : data.size_value,
            size_unit: data.size_value === '' ? null : data.size_unit,
            beds: beds.filter((b) => b.bed_type),
            amenities: [...amenities],
            products: products.filter((p) => p.enabled || rt?.products.some((x) => x.rate_plan === p.rate_plan_id && x.is_active)).map((p) => ({
                rate_plan_id: p.rate_plan_id, enabled: p.enabled, pricing_mode: p.pricing_mode,
                default_price: p.pricing_mode === 'manual' && p.default_price !== '' ? p.default_price : null,
                parent_rate_plan_id: p.pricing_mode === 'derived' ? p.parent_rate_plan_id || null : null,
                adjust_type: p.pricing_mode === 'derived' ? p.adjust_type : null,
                adjust_value: p.pricing_mode === 'derived' ? p.adjust_value : null,
                is_default: p.is_default, occupancy_rules: p.occupancy_rules,
            })),
        };
        if (unitMode === 'quantity') {
            body.quantity = Number(quantity) || 0;
            if (!editing) body.floor = floor || null;
        } else {
            body.units = units.map((u) => ({ id: u.id, name: u.name, floor: u.floor || null, is_active: u.is_active }));
        }

        const res = await act(
            () => editing
                ? http.put<{ message: string; room_type: RoomType }>(propertyApiUrl(`/room-types/${rt.id}`), body)
                : http.post<{ message: string; room_type: RoomType }>(propertyApiUrl('/room-types'), body),
            (e) => { setError(e); toast.error(e.message); },
        );
        setSaving(false);
        if (res) window.location.href = editing ? propertyUrl('/room-types') : propertyUrl(`/room-types/${res.room_type.id}/edit`);
    };

    const upload = async (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file || !rt) return;
        setUploading(true);
        const form = new FormData();
        form.append('image', file);
        try {
            const res = await fetch(propertyApiUrl(`/room-types/${rt.id}/images`), {
                method: 'POST', body: form, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
            });
            const json = await res.json();
            if (!res.ok) {
                const fields = json?.error?.fields as Record<string, string[]> | undefined;
                toast.error(fields?.image?.[0] ?? json?.error?.message ?? t('errors.500'));
            } else {
                setImages((list) => [...list, json.image]);
                toast.success(json.message);
            }
        } finally {
            setUploading(false);
        }
    };

    const deleteImage = async () => {
        if (!rt || removeImage === null) return;
        const id = removeImage;
        const res = await act(() => http.delete<{ message: string }>(propertyApiUrl(`/room-types/${rt.id}/images/${id}`)));
        if (res) setImages((list) => list.filter((i) => i.id !== id));
        setRemoveImage(null);
    };

    const ratePlanLabel = (id: string) => options.rate_plans.find((p) => p.value === id)?.label ?? id;

    return (
        <div className="content">
            <PageHeader back={propertyUrl('/room-types')} title={editing ? `${t('rooms.edit_room_type')}: ${rt.name}` : t('rooms.add_room_type')}
                description={t('rooms.room_types_desc')} />

            <div className="form-page">
                {error && Object.keys(error.fields).length > 0 && <Alert tone="danger">{t('errors.validation')}</Alert>}

                <FormSection title={t('rooms.sections.basic')} description={t('rooms.sections.basic_desc')}>
                    <Input fieldClass="span-6" label={t('rooms.fields.name')} required value={data.name} maxLength={120} onChange={(e) => set('name', e.target.value)} error={err('name')} />
                    <Input fieldClass="span-3" label={t('rooms.fields.code')} required value={data.code} maxLength={20} hint={t('rooms.hints.code')}
                        onChange={(e) => set('code', e.target.value.toUpperCase())} error={err('code')} />
                    <Select fieldClass="span-3" label={t('rooms.fields.category')} optional value={data.category} placeholder={t('ui.none')} options={options.categories}
                        onChange={(e) => set('category', e.target.value)} error={err('category')} />
                    <Textarea fieldClass="span-9" label={t('rooms.fields.description')} optional rows={3} value={data.description} maxLength={5000}
                        onChange={(e) => set('description', e.target.value)} error={err('description')} />
                    <div className="field span-3">
                        <span className="field-label">{t('rooms.fields.status')}</span>
                        <Toggle checked={data.is_active} onChange={(v) => set('is_active', v)} label={t(`ui.status.${data.is_active ? 'active' : 'inactive'}`)} />
                    </div>
                </FormSection>

                <FormSection title={t('rooms.sections.occupancy')} description={t('rooms.sections.occupancy_desc')}>
                    <Input fieldClass="span-3" type="number" min={1} max={20} label={t('rooms.fields.base_adults')} required value={data.base_adults} onChange={num('base_adults')} error={err('base_adults')} />
                    <Input fieldClass="span-3" type="number" min={1} max={20} label={t('rooms.fields.max_adults')} required value={data.max_adults} onChange={num('max_adults')} error={err('max_adults')} />
                    <Input fieldClass="span-3" type="number" min={0} max={20} label={t('rooms.fields.max_children')} value={data.max_children} onChange={num('max_children')} error={err('max_children')} />
                    <Input fieldClass="span-3" type="number" min={0} max={10} label={t('rooms.fields.max_infants')} value={data.max_infants} onChange={num('max_infants')} error={err('max_infants')} />
                    <Input fieldClass="span-4" type="number" min={1} max={40} label={t('rooms.fields.max_occupancy')} required value={data.max_occupancy} onChange={num('max_occupancy')} error={err('max_occupancy')} />
                    <div className="field span-4">
                        <span className="field-label">{t('rooms.fields.extra_bed_allowed')}</span>
                        <Toggle checked={data.extra_bed_allowed} onChange={(v) => setData((d) => ({ ...d, extra_bed_allowed: v, max_extra_beds: v ? Math.max(1, d.max_extra_beds) : 0 }))}
                            label={data.extra_bed_allowed ? t('ui.yes') : t('ui.no')} />
                    </div>
                    <Input fieldClass="span-4" type="number" min={0} max={5} label={t('rooms.fields.max_extra_beds')} disabled={!data.extra_bed_allowed}
                        value={data.max_extra_beds} onChange={num('max_extra_beds')} error={err('max_extra_beds')} />
                </FormSection>

                <FormSection title={t('rooms.sections.room_details')}>
                    <Input fieldClass="span-3" type="number" min={0} step="0.1" label={t('rooms.fields.size')} optional value={data.size_value}
                        onChange={(e) => set('size_value', e.target.value)} error={err('size_value')} />
                    <Select fieldClass="span-2" label={t('rooms.fields.size_unit')} value={data.size_unit}
                        options={[{ value: 'sqm', label: t('rooms.size_units.sqm') }, { value: 'sqft', label: t('rooms.size_units.sqft') }]}
                        onChange={(e) => set('size_unit', e.target.value)} error={err('size_unit')} />
                    <Select fieldClass="span-3" label={t('rooms.fields.smoking_policy')} value={data.smoking_policy}
                        options={['non_smoking', 'smoking', 'both'].map((v) => ({ value: v, label: t(`rooms.smoking.${v}`) }))}
                        onChange={(e) => set('smoking_policy', e.target.value)} />
                    <Input fieldClass="span-4" label={t('rooms.fields.view_label')} optional value={data.view_label} maxLength={60} onChange={(e) => set('view_label', e.target.value)} error={err('view_label')} />
                </FormSection>

                <FormSection title={t('rooms.sections.beds')} description={t('rooms.sections.beds_desc')}
                    actions={<Button size="sm" variant="outline" icon="plus" onClick={() => setBeds((b) => [...b, { bed_type: '', quantity: 1 }])}>{t('rooms.add_bed')}</Button>}>
                    <div className="span-12 edit-rows">
                        {beds.map((b, i) => (
                            <div key={i} className="edit-row beds">
                                <Select label={i === 0 ? t('rooms.fields.bed_type') : undefined} aria-label={t('rooms.fields.bed_type')} value={b.bed_type} placeholder="—" options={options.bed_types}
                                    onChange={(e) => setBeds((list) => list.map((x, j) => (j === i ? { ...x, bed_type: e.target.value } : x)))} error={err(`beds.${i}.bed_type`)} />
                                <Input label={i === 0 ? t('rooms.fields.quantity') : undefined} aria-label={t('rooms.fields.quantity')} type="number" min={1} max={10} value={b.quantity}
                                    onChange={(e) => setBeds((list) => list.map((x, j) => (j === i ? { ...x, quantity: Number(e.target.value) } : x)))} error={err(`beds.${i}.quantity`)} />
                                <Button variant="ghost" icon="trash" title={t('ui.delete')} aria-label={t('ui.delete')} onClick={() => setBeds((list) => list.filter((_, j) => j !== i))} />
                            </div>
                        ))}
                        {errorsUnder(error, 'beds').length > 0 && !err('beds.0.bed_type') && <div className="field-error">{errorsUnder(error, 'beds')[0]}</div>}
                    </div>
                </FormSection>

                <FormSection title={t('rooms.sections.amenities')} description={t('rooms.sections.amenities_desc')}
                    actions={<LinkButton size="sm" variant="ghost" icon="settings" href={propertyUrl('/amenities')}>{t('rooms.manage_amenities')}</LinkButton>}>
                    <div className="span-12">
                        {groupedAmenities.map((g) => (
                            <div key={g.value} className="amenity-group">
                                <h4>{g.label}</h4>
                                <div className="amenity-grid">
                                    {g.items.map((a) => (
                                        <Checkbox key={a.value} checked={amenities.has(a.value)} label={a.label}
                                            onChange={() => setAmenities((s) => { const n = new Set(s); if (n.has(a.value)) n.delete(a.value); else n.add(a.value); return n; })} />
                                    ))}
                                </div>
                            </div>
                        ))}
                        {err('amenities') && <div className="field-error">{err('amenities')}</div>}
                    </div>
                </FormSection>

                <FormSection title={t('rooms.sections.inventory')} description={t('rooms.sections.inventory_desc')}
                    actions={<Segmented active={unitMode} onChange={(k) => setUnitMode(k as 'quantity' | 'names')} items={[
                        { key: 'quantity', label: t('rooms.use_quantity') }, { key: 'names', label: t('rooms.name_rooms_individually') },
                    ]} />}>
                    {usage.units !== null && <div className="span-12"><Alert tone="info">{t('rooms.hints.plan_usage', { used: usage.used_units, max: usage.units })}</Alert></div>}
                    {unitMode === 'quantity' ? <>
                        <Input fieldClass="span-4" type="number" min={editing ? rt.active_units : 0} max={500} label={t('rooms.fields.rooms_quantity')} required value={quantity}
                            onChange={(e) => setQuantity(e.target.value)} error={err('quantity') ?? err('units')}
                            hint={editing ? t('rooms.hints.quantity_edit', { count: rt.active_units }) : t('rooms.hints.quantity', { code: data.code || 'DLX' })} />
                        {!editing && <Input fieldClass="span-3" label={t('rooms.fields.floor')} optional value={floor} maxLength={10} onChange={(e) => setFloor(e.target.value)} />}
                    </> : (
                        <div className="span-12 edit-rows">
                            {units.map((u, i) => (
                                <div key={u.id ?? `new-${i}`} className="edit-row units">
                                    <Input aria-label={t('rooms.fields.room_name')} placeholder={t('rooms.fields.room_name')} value={u.name} maxLength={30}
                                        onChange={(e) => setUnits((l) => l.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} error={err(`units.${i}.name`)} />
                                    <Input aria-label={t('rooms.fields.floor')} placeholder={t('rooms.fields.floor')} value={u.floor ?? ''} maxLength={10}
                                        onChange={(e) => setUnits((l) => l.map((x, j) => (j === i ? { ...x, floor: e.target.value } : x)))} />
                                    <Toggle checked={u.is_active} onChange={(v) => setUnits((l) => l.map((x, j) => (j === i ? { ...x, is_active: v } : x)))}
                                        label={t(`ui.status.${u.is_active ? 'active' : 'inactive'}`)} />
                                    {u.id === null
                                        ? <Button variant="ghost" icon="trash" title={t('ui.delete')} aria-label={t('ui.delete')} onClick={() => setUnits((l) => l.filter((_, j) => j !== i))} />
                                        : <span />}
                                </div>
                            ))}
                            {errorsUnder(error, 'units').length > 0 && <div className="field-error">{errorsUnder(error, 'units')[0]}</div>}
                            <div><Button size="sm" variant="outline" icon="plus" onClick={() => setUnits((l) => [...l, { id: null, name: '', floor: l[l.length - 1]?.floor ?? '', is_active: true }])}>{t('rooms.add_room_row')}</Button></div>
                        </div>
                    )}
                </FormSection>

                <FormSection title={t('rooms.sections.rate_plans')} description={t('rooms.sections.rate_plans_desc')}>
                    <div className="span-12">
                        {options.rate_plans.length === 0 ? <p className="muted">{t('rooms.no_rate_plans')}</p> : (
                            <div className="table-wrap"><div className="table-scroll">
                                <table className="table product-table">
                                    <thead><tr>
                                        <th>{t('rates.rate_plan')}</th><th>{t('rooms.enabled')}</th><th>{t('rates.fields.pricing')}</th>
                                        <th>{t('rooms.price')}</th><th>{t('rates.sections.occupancy')}</th><th>{t('rooms.make_default')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {products.map((p, i) => [
                                            <tr key={p.rate_plan_id}>
                                                <td className="cell-main">{ratePlanLabel(p.rate_plan_id)}</td>
                                                <td><Checkbox aria-label={t('rooms.enabled')} checked={p.enabled} onChange={(e) => setProduct(i, { enabled: e.target.checked })} /></td>
                                                <td>
                                                    <Select size="sm" aria-label={t('rates.fields.pricing')} disabled={!p.enabled} value={p.pricing_mode}
                                                        options={[{ value: 'manual', label: t('rooms.manual') }, { value: 'derived', label: t('rooms.derived') }]}
                                                        onChange={(e) => setProduct(i, { pricing_mode: e.target.value as 'manual' | 'derived' })} />
                                                </td>
                                                <td>
                                                    {p.pricing_mode === 'manual'
                                                        ? <MoneyInput value={p.default_price} onChange={(v) => setProduct(i, { default_price: v })} error={err(`products.${i}.default_price`)} />
                                                        : <div className="inline-fields">
                                                            <Select size="sm" aria-label={t('rooms.derived_from')} disabled={!p.enabled} value={p.parent_rate_plan_id} placeholder={t('rooms.derived_from')}
                                                                options={options.rate_plans.filter((x) => x.value !== p.rate_plan_id)} onChange={(e) => setProduct(i, { parent_rate_plan_id: e.target.value })}
                                                                error={err(`products.${i}.parent_rate_plan_id`)} />
                                                            <Select size="sm" aria-label={t('rates.fields.adjust_type')} value={p.adjust_type}
                                                                options={['percent', 'fixed', 'fixed_per_person'].map((v) => ({ value: v, label: t(`rates.adjust_types.${v}`) }))}
                                                                onChange={(e) => setProduct(i, { adjust_type: e.target.value })} />
                                                            <Input size="sm" type="number" step="0.01" aria-label={t('rooms.adjustment')} value={p.adjust_value} className="num"
                                                                onChange={(e) => setProduct(i, { adjust_value: e.target.value })} error={err(`products.${i}.adjust_value`)} />
                                                        </div>}
                                                    {rt?.products.find((x) => x.rate_plan === p.rate_plan_id)?.base_price && p.pricing_mode === 'derived' &&
                                                        <div className="field-hint">{price(rt.products.find((x) => x.rate_plan === p.rate_plan_id)?.base_price)}</div>}
                                                </td>
                                                <td>
                                                    <Button size="sm" variant="ghost" iconRight={p.open ? 'chevron-up' : 'chevron-down'} disabled={!p.enabled}
                                                        onClick={() => setProduct(i, { open: !p.open })}>{p.occupancy_rules.length}</Button>
                                                </td>
                                                <td className="radio-cell">
                                                    <input type="radio" name="default-rate-plan" aria-label={t('rooms.make_default')} title={t('rooms.make_default')} disabled={!p.enabled}
                                                        checked={p.is_default} onChange={() => setProducts((l) => l.map((x, j) => ({ ...x, is_default: j === i })))} />
                                                </td>
                                            </tr>,
                                            p.open && p.enabled ? (
                                                <tr key={`${p.rate_plan_id}-occ`} className="sub-row"><td colSpan={6}>
                                                    <p className="muted text-sm" style={{ marginBottom: 8 }}>{t('rates.sections.occupancy_desc')}</p>
                                                    <OccupancyEditor rules={p.occupancy_rules} onChange={(rules) => setProduct(i, { occupancy_rules: rules })}
                                                        ageBands={options.age_bands} errorPrefix={`products.${i}.occupancy_rules`} error={error} />
                                                </td></tr>
                                            ) : null,
                                        ])}
                                    </tbody>
                                </table>
                            </div></div>
                        )}
                        {errorsUnder(error, 'products').filter((m) => m).slice(0, 1).map((m) => <div key={m} className="field-error" style={{ marginTop: 8 }}>{m}</div>)}
                    </div>
                </FormSection>

                <FormSection title={t('rooms.sections.images')} description={editing ? t('rooms.sections.images_desc') : t('rooms.sections.images_after_save')}>
                    {editing && (
                        <div className="span-12 image-grid">
                            {images.map((img) => (
                                <div key={img.id} className="image-tile">
                                    <img src={img.url} alt={img.alt ?? rt.name} />
                                    <Button size="sm" variant="secondary" icon="trash" title={t('rooms.remove_image')} aria-label={t('rooms.remove_image')} onClick={() => setRemoveImage(img.id)} />
                                </div>
                            ))}
                            <label className="upload-tile" title={t('rooms.upload_image')}>
                                <input type="file" accept="image/jpeg,image/png,image/webp" onChange={upload} disabled={uploading} />
                                <span><Icon name={uploading ? 'loader' : 'upload'} size={24} className={uploading ? 'spin' : undefined} /><br />{t('rooms.upload_image')}</span>
                            </label>
                        </div>
                    )}
                </FormSection>

                <div className="form-footer">
                    <span className="spacer" />
                    <LinkButton href={propertyUrl('/room-types')}>{t('ui.cancel')}</LinkButton>
                    <Button variant="primary" icon="save" loading={saving} onClick={save}>{editing ? t('rooms.save_room_type') : t('rooms.create_room_type')}</Button>
                </div>
            </div>

            <ConfirmDialog open={removeImage !== null} danger title={t('rooms.remove_image')} message={t('rooms.remove_image')} confirmLabel={t('ui.delete')}
                onConfirm={deleteImage} onClose={() => setRemoveImage(null)} />
        </div>
    );
}

createPage(RoomTypeForm);
