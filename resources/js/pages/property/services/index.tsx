import { useEffect, useState } from 'react';
import { Badge, Button, EmptyState, Input, PageHeader, Pagination, PillTabs, RowMenu, Select, SidePanel, Textarea, Toggle, toast, type Column, type PageMeta, DataTable } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { money } from '@/lib/format';
import { http, navigateWithQuery, ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { payload, propertyApiUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';

interface Row {
    id: string; code: string; name: string; description: string | null; price: string; tax_category: string | null; sac_hsn_code: string | null;
    posting_rule: string; is_active: boolean; sort_order: number; times_posted: number;
}
interface Props {
    list: { rows: Row[]; meta: PageMeta; counts: { all: number; active: number; inactive: number } };
    filters: { q?: string; status?: string; posting_rule?: string; selected?: string };
    options: { tax_categories: { value: string; label: string; sac: string | null }[]; posting_rules: string[]; show_sac?: boolean };
}

const currency = () => payload().shell.property?.currency ?? '';

/** Services & extras that can be posted to folios (extra bed, airport pickup, laundry…). */
function ServicesPage({ list, filters, options }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [selected, setSelected] = useQueryState('selected', filters.selected ?? '');
    const [rows, setRows] = useState(list.rows);
    const categoryLabel = (code: string | null) => options.tax_categories.find((c) => c.value === code)?.label ?? '—';

    const toggle = async (r: Row, active: boolean) => {
        setRows((l) => l.map((x) => (x.id === r.id ? { ...x, is_active: active } : x)));
        try {
            const res = await http.post<{ message: string }>(propertyApiUrl(`/services/${r.id}/status`), { is_active: active });
            toast.success(res.message);
        } catch (e) {
            setRows((l) => l.map((x) => (x.id === r.id ? { ...x, is_active: !active } : x)));
            toast.error((e as ApiError).message, (e as ApiError).ref);
        }
    };

    const columns: Column<Row>[] = [
        { key: 'name', header: t('billing.services.columns.name'), sortable: true, render: (r) => <><span className="cell-main">{r.name}</span>{r.description && <span className="cell-sub">{r.description}</span>}</> },
        { key: 'code', header: t('billing.services.columns.code'), sortable: true, render: (r) => r.code },
        { key: 'price', header: t('billing.services.columns.price'), sortable: true, align: 'right', render: (r) => money(r.price, currency()) },
        { key: 'rule', header: t('billing.services.columns.posting_rule'), render: (r) => t(`billing.posting_rules.${r.posting_rule}`) },
        { key: 'tax', header: t('billing.services.columns.tax_category'), className: 'hide-with-panel', render: (r) => <>{categoryLabel(r.tax_category)}{options.show_sac && r.sac_hsn_code && <span className="cell-sub block">SAC/HSN {r.sac_hsn_code}</span>}</> },
        { key: 'used', header: t('billing.services.columns.used'), align: 'right', className: 'hide-with-panel', render: (r) => r.times_posted },
        { key: 'status', header: t('billing.services.columns.status'), render: (r) => <span onClick={(e) => e.stopPropagation()}><Toggle checked={r.is_active} onChange={(v) => toggle(r, v)} label={t(`ui.status.${r.is_active ? 'active' : 'inactive'}`)} /></span> },
        { key: 'actions', header: t('ui.actions'), className: 'col-actions', render: (r) => <RowMenu items={[{ label: t('ui.edit'), icon: 'pencil', onClick: () => setSelected(r.id) }]} /> },
    ];
    const hasFilters = !!(filters.q || filters.status || filters.posting_rule);

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('billing.services.title')} description={t('billing.services.description')}
                    actions={<Button variant="primary" icon="plus" onClick={() => setSelected('new')}>{t('billing.services.add')}</Button>} />
                <form className="filter-bar" onSubmit={(e) => { e.preventDefault(); navigateWithQuery({ q }); }}>
                    <Input fieldClass="search" label={t('ui.search')} icon="search" type="search" placeholder={t('billing.services.search')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select label={t('billing.services.columns.posting_rule')} value={filters.posting_rule ?? ''} placeholder={t('ui.all')}
                        options={options.posting_rules.map((p) => ({ value: p, label: t(`billing.posting_rules.${p}`) }))} onChange={(e) => navigateWithQuery({ posting_rule: e.target.value })} />
                    {hasFilters && <Button variant="ghost" icon="x" onClick={() => navigateWithQuery({ q: null, status: null, posting_rule: null })}>{t('ui.reset')}</Button>}
                </form>
                <PillTabs active={filters.status || 'all'} onChange={(k) => navigateWithQuery({ status: k === 'all' ? null : k })}
                    items={(['all', 'active', 'inactive'] as const).map((k) => ({ key: k, label: k === 'all' ? t('ui.all') : t(`ui.status.${k}`), count: list.counts[k] }))} />
                <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} onRowClick={(r) => setSelected(r.id)} selectedKey={selected}
                    empty={!hasFilters && list.counts.all === 0 ? <EmptyState icon="concierge-bell" title={t('billing.services.none')} text={t('billing.services.none_hint')}
                        action={<Button variant="primary" icon="plus" onClick={() => setSelected('new')}>{t('billing.services.add')}</Button>} /> : undefined} />
                <Pagination meta={list.meta} label={t('billing.services.item_plural')} />
            </div>
            {selected && <ServicePanel id={selected} options={options} onClose={() => setSelected(null)} onSaved={(id) => navigateWithQuery({ selected: id }, false)} />}
        </div>
    );
}

const blank = (): Row => ({ id: '', code: '', name: '', description: '', price: '', tax_category: 'service', sac_hsn_code: '', posting_rule: 'once', is_active: true, sort_order: 0, times_posted: 0 });

function ServicePanel({ id, options, onClose, onSaved }: { id: string; options: Props['options']; onClose: () => void; onSaved: (id: string) => void }) {
    const [svc, setSvc] = useState<Row | null>(id === 'new' ? blank() : null);
    const [failed, setFailed] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        setError(null);
        setFailed(null);
        if (id === 'new') { setSvc(blank()); return; }
        setSvc(null);
        http.get<{ service: Row }>(propertyApiUrl(`/services/${id}`)).then((r) => alive && setSvc(r.service)).catch((e: ApiError) => alive && setFailed(e.message));
        return () => { alive = false; };
    }, [id]);

    if (failed) return <SidePanel title={t('billing.services.title')} onClose={onClose}><EmptyState icon="circle-alert" title={failed} /></SidePanel>;
    if (!svc) return <SidePanel title={t('ui.loading')} onClose={onClose}><div className="sp-section"><div className="skeleton" style={{ height: 320 }} /></div></SidePanel>;

    const set = <K extends keyof Row>(k: K, v: Row[K]) => setSvc({ ...svc, [k]: v });
    const err = (k: string) => error?.field(k);
    const save = async () => {
        setBusy(true);
        setError(null);
        const body = { code: svc.code, name: svc.name, description: svc.description || null, price: svc.price, tax_category: svc.tax_category,
            sac_hsn_code: svc.sac_hsn_code || null, posting_rule: svc.posting_rule, is_active: svc.is_active, sort_order: Number(svc.sort_order) || 0 };
        try {
            const res = svc.id
                ? await http.put<{ message: string; service: Row }>(propertyApiUrl(`/services/${svc.id}`), body)
                : await http.post<{ message: string; service: Row }>(propertyApiUrl('/services'), body);
            toast.success(res.message);
            onSaved(res.service.id);
        } catch (e) {
            const a = e as ApiError;
            if (a.status === 422) {
                setError(a);
                requestAnimationFrame(() => document.querySelector<HTMLElement>('.side-panel [aria-invalid="true"]')?.focus());
            } else toast.error(a.message, a.ref);
        } finally {
            setBusy(false);
        }
    };
    const sacHint = options.tax_categories.find((c) => c.value === svc.tax_category)?.sac;

    return (
        <SidePanel title={svc.id ? svc.name : t('billing.services.new')} onClose={onClose} headerExtra={svc.id ? <Badge status={svc.is_active ? 'active' : 'inactive'}>{t(`ui.status.${svc.is_active ? 'active' : 'inactive'}`)}</Badge> : undefined}>
            <form className="sp-section" onSubmit={(e) => { e.preventDefault(); save(); }}>
                <div className="form-grid">
                    <Input fieldClass="span-12" label={t('billing.services.fields.name')} required autoFocus maxLength={120} value={svc.name} onChange={(e) => set('name', e.target.value)} error={err('name')} />
                    <Input fieldClass="span-6" label={t('billing.services.fields.code')} required maxLength={30} value={svc.code} onChange={(e) => set('code', e.target.value.toUpperCase())} error={err('code')} />
                    <Input fieldClass="span-6" type="number" min={0} step="0.01" label={t('billing.services.fields.price')} required suffix={currency()} className="num"
                        value={svc.price} onChange={(e) => set('price', e.target.value)} error={err('price')} />
                    <Select fieldClass="span-12" label={t('billing.services.fields.posting_rule')} required value={svc.posting_rule}
                        options={options.posting_rules.map((p) => ({ value: p, label: t(`billing.posting_rules.${p}`) }))} onChange={(e) => set('posting_rule', e.target.value)}
                        error={err('posting_rule')} hint={t('billing.services.posting_hint')} />
                    <Select fieldClass="span-6" label={t('billing.services.fields.tax_category')} required value={svc.tax_category ?? ''} options={options.tax_categories}
                        onChange={(e) => set('tax_category', e.target.value)} error={err('tax_category')} />
                    {options.show_sac && <Input fieldClass="span-6" label={t('billing.services.fields.sac')} optional maxLength={10} value={svc.sac_hsn_code ?? ''} placeholder={sacHint ?? ''}
                        onChange={(e) => set('sac_hsn_code', e.target.value)} error={err('sac_hsn_code')} />}
                    <Textarea fieldClass="span-12" label={t('billing.services.fields.description')} optional rows={2} maxLength={255} value={svc.description ?? ''} onChange={(e) => set('description', e.target.value)} />
                    <Input fieldClass="span-6" type="number" min={0} max={9999} label={t('billing.services.fields.sort_order')} value={svc.sort_order} onChange={(e) => set('sort_order', Number(e.target.value))} />
                    <div className="field span-6">
                        <span className="field-label">{t('billing.services.columns.status')}</span>
                        <Toggle checked={svc.is_active} onChange={(v) => set('is_active', v)} label={t(`ui.status.${svc.is_active ? 'active' : 'inactive'}`)} />
                    </div>
                </div>
                <button type="submit" hidden />
            </form>
            <div className="sp-section row">
                <span className="grow" />
                <Button onClick={onClose}>{t('ui.cancel')}</Button>
                <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save_changes')}</Button>
            </div>
        </SidePanel>
    );
}

createPage(ServicesPage);
