import { useState } from 'react';
import { Alert, Badge, Button, DataTable, Icon, Input, Modal, PageHeader, Pagination, PillTabs, Select, Toggle, type Column, type Option, type PageMeta } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, navigateWithQuery, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, fieldError, labelOf } from '../_accommodation/shared';

interface Row { id: string; name: string; raw_name: string; category: string; icon: string | null; custom: boolean; is_active: boolean; room_types_count: number }
interface Props {
    list: { rows: Row[]; meta: PageMeta; counts: { all: number; global: number; custom: number } };
    filters: { q?: string; category?: string; tab?: string };
    options: { categories: Option[] };
    can: { update: boolean };
}

const ICONS = ['check', 'sparkles', 'wifi', 'tv', 'coffee', 'wine', 'utensils', 'bath', 'waves', 'sun', 'mountain', 'car', 'dumbbell', 'concierge-bell', 'baby', 'shirt', 'armchair', 'plane'];

/** Amenities: standard catalogue plus the property's own (simple list and edit). */
function AmenitiesPage({ list, filters, options, can }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [editing, setEditing] = useState<Row | 'new' | null>(null);

    const columns: Column<Row>[] = [
        { key: 'name', header: t('amenities.columns.name'), render: (r) => <span className="row"><Icon name={r.icon ?? 'check'} size={18} /><span className="cell-main">{r.name}</span></span> },
        { key: 'category', header: t('amenities.columns.category'), render: (r) => labelOf(options.categories, r.category) },
        { key: 'source', header: t('amenities.columns.source'), render: (r) => <Badge size="sm" tone={r.custom ? 'violet' : 'slate'}>{r.custom ? t('amenities.custom') : t('amenities.global')}</Badge> },
        { key: 'used', header: t('amenities.columns.used'), align: 'right', render: (r) => r.room_types_count },
        { key: 'status', header: t('amenities.columns.status'), render: (r) => <Badge size="sm" status={r.is_active ? 'active' : 'inactive'} /> },
        {
            key: 'actions', header: t('amenities.columns.actions'), className: 'col-actions', render: (r) => r.custom && can.update
                ? <Button size="sm" variant="outline" icon="pencil" onClick={() => setEditing(r)}>{t('ui.edit')}</Button>
                : <span className="muted" title={t('amenities.read_only')}><Icon name="lock" size={16} /></span>,
        },
    ];

    return (
        <div className="content">
            <PageHeader back={propertyUrl('/room-types')} title={t('amenities.title')} description={t('amenities.description')} actions={
                can.update && <Button variant="primary" icon="plus" onClick={() => setEditing('new')}>{t('amenities.add')}</Button>
            } />
            <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('amenities.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                <Select label={t('amenities.columns.category')} value={filters.category ?? ''} placeholder={t('amenities.all_categories')} options={options.categories} onChange={(e) => navigateWithQuery({ category: e.target.value })} />
                {(filters.q || filters.category) && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, category: null })}>{t('ui.reset')}</Button>}
            </form>
            <PillTabs active={filters.tab || 'all'} onChange={(k) => navigateWithQuery({ tab: k === 'all' ? null : k })}
                items={(['all', 'global', 'custom'] as const).map((k) => ({ key: k, label: t(`amenities.tabs.${k}`), count: list.counts[k] }))} />
            <DataTable columns={columns} rows={list.rows} rowKey={(r) => r.id} />
            <Pagination meta={list.meta} label={t('amenities.item_plural')} />
            {editing && <AmenityModal amenity={editing === 'new' ? null : editing} categories={options.categories} onClose={() => setEditing(null)} />}
        </div>
    );
}

function AmenityModal({ amenity, categories, onClose }: { amenity: Row | null; categories: Option[]; onClose: () => void }) {
    const [form, setForm] = useState({ name: amenity?.raw_name ?? '', category: amenity?.category ?? 'room', icon: amenity?.icon ?? 'check', is_active: amenity?.is_active ?? true });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => amenity
            ? http.put<{ message: string }>(propertyApiUrl(`/amenities/${amenity.id}`), form)
            : http.post<{ message: string }>(propertyApiUrl('/amenities'), form), setError);
        setBusy(false);
        if (res) window.location.reload();
    };

    return (
        <Modal open title={amenity ? t('amenities.edit') : t('amenities.add')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save')}</Button>
        </>}>
            <div className="form-grid">
                {error && !error.fields.name && !error.fields.category && Object.keys(error.fields).length > 0 && <div className="span-12"><Alert tone="danger">{Object.values(error.fields)[0][0]}</Alert></div>}
                <Input fieldClass="span-12" label={t('amenities.fields.name')} required autoFocus maxLength={80} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={fieldError(error, 'name')} />
                <Select fieldClass="span-6" label={t('amenities.fields.category')} value={form.category} options={categories} onChange={(e) => setForm({ ...form, category: e.target.value })} error={fieldError(error, 'category')} />
                <div className="field span-6">
                    <span className="field-label">{t('amenities.fields.icon')}</span>
                    <div className="row" style={{ flexWrap: 'wrap', gap: 6 }}>
                        {ICONS.map((i) => (
                            <button key={i} type="button" title={i} aria-label={i} aria-pressed={form.icon === i} onClick={() => setForm({ ...form, icon: i })}
                                className={`btn btn-sm btn-icon ${form.icon === i ? 'btn-primary' : 'btn-secondary'}`}><Icon name={i} size={15} /></button>
                        ))}
                    </div>
                </div>
                {amenity && <div className="field span-12">
                    <span className="field-label">{t('amenities.fields.status')}</span>
                    <Toggle checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} label={t(`ui.status.${form.is_active ? 'active' : 'inactive'}`)} />
                </div>}
            </div>
        </Modal>
    );
}

createPage(AmenitiesPage);
