import { useState } from 'react';
import { Badge, Button, ConfirmDialog, Dropdown, LinkButton, PageHeader, toast } from '@/components/ui';
import { CopyPropertyDialog } from '@/components/property/CopyPropertyDialog';
import { PropertyFilters, PropertyKpiRow, PropertyStatusTabs, PropertyTable, type PropertyListProps } from '@/components/property/PropertyList';
import { PropertyPanel } from '@/components/property/PropertyPanel';
import type { PropertyDetail, PropertyRow } from '@/components/property/types';
import { createPage } from '@/lib/boot';
import { downloadCsv } from '@/lib/csv';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { useQueryState } from '@/lib/use';

interface Props extends PropertyListProps { selected: string }

type StatusChange = { code: string; name: string; status: 'active' | 'inactive' };
type BulkStatus = 'active' | 'inactive' | 'suspended';

/** Super Admin → Properties Management. */
function AdminPropertiesPage(props: Props) {
    const [selected, select] = useQueryState('selected', props.selected || props.rows[0]?.code || '');
    const [change, setChange] = useState<StatusChange | null>(null);
    const [busy, setBusy] = useState(false);
    const [checked, setChecked] = useState<Set<string>>(new Set());
    const [bulk, setBulk] = useState<BulkStatus | null>(null);
    const [copying, setCopying] = useState<PropertyDetail | null>(null);

    const applyBulk = async () => {
        if (!bulk) return;
        setBusy(true);
        try {
            const res = await http.post<{ message: string }>('/web-api/admin/properties/bulk-status', { codes: [...checked], status: bulk });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setBusy(false);
            setBulk(null);
        }
    };

    const bulkStart = (status: BulkStatus) => (checked.size === 0 ? toast.error(t('admin.bulk_none')) : setBulk(status));

    const applyStatus = async () => {
        if (!change) return;
        setBusy(true);
        try {
            const res = await http.post<{ message: string }>(`/web-api/admin/properties/${change.code}/status`, { status: change.status });
            toast.success(res.message);
            window.location.reload();
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
            setBusy(false);
            setChange(null);
        }
    };

    // Export: the ticked rows, or the whole current page when nothing is ticked.
    const exportRows = (onlyChecked = false) => downloadCsv('properties.csv',
        [t('property.code'), t('property.name'), t('property.location'), t('property.type'), t('property.total_rooms'), t('ui.status_label'), t('subscription.plan')],
        props.rows.filter((r) => !onlyChecked || checked.has(r.code))
            .map((r) => [r.code, r.name, r.location, r.type_label, r.rooms, t(`ui.status.${r.status}`), r.plan]));

    const statusAction = (r: Pick<PropertyRow, 'code' | 'name' | 'status'>) => r.status === 'active'
        ? { label: t('property.deactivate'), icon: 'pause', danger: true, onClick: () => setChange({ code: r.code, name: r.name, status: 'inactive' }) }
        : { label: t('property.activate'), icon: 'check-circle', onClick: () => setChange({ code: r.code, name: r.name, status: 'active' }) };

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('admin.properties_title')} description={t('admin.properties_sub')} actions={<>
                    <Dropdown align="right" width={220} trigger={(toggle) => (
                        <Button icon="layers" onClick={toggle} title={t('admin.bulk_actions')}>
                            {t('admin.bulk_actions')}{checked.size > 0 && <span className="count-pill">{checked.size}</span>}
                        </Button>
                    )} items={[
                        { label: t('admin.bulk_activate'), icon: 'check-circle', onClick: () => bulkStart('active') },
                        { label: t('admin.bulk_deactivate'), icon: 'pause', onClick: () => bulkStart('inactive') },
                        { label: t('admin.bulk_suspend'), icon: 'ban', danger: true, onClick: () => bulkStart('suspended') },
                        { label: '', separator: true },
                        { label: t('admin.bulk_export'), icon: 'download', onClick: () => (checked.size === 0 ? toast.error(t('admin.bulk_none')) : exportRows(true)) },
                    ]} />
                    <Button icon="download" onClick={() => exportRows(false)} disabled={props.rows.length === 0}>{t('ui.export')}</Button>
                    <LinkButton variant="primary" icon="plus" href="/admin/properties/new">{t('admin.add_property')}</LinkButton>
                </>} />
                <PropertyKpiRow kpis={props.kpis} withRooms />
                <PropertyFilters filters={props.filters} options={props.options} />
                <PropertyStatusTabs counts={props.counts} active={props.filters.status} statuses={props.options.statuses} />
                <PropertyTable rows={props.rows} meta={props.meta} selected={selected} onSelect={(r) => select(r.code)} checked={checked} onCheck={setChecked}
                    extra={[{ key: 'plan', header: t('subscription.plan'), render: (r) => <span className="row" style={{ gap: 8 }}>{r.plan ?? '—'}<Badge size="sm" status={r.subscription_status} /></span> }]}
                    menu={(r) => [
                        { label: t('ui.view'), icon: 'eye', onClick: () => select(r.code) },
                        { label: t('admin.edit_property'), icon: 'pencil', href: `/admin/properties/${r.code}/edit` },
                        { label: t('property.open_property'), icon: 'arrow-right', href: `/p/${r.code}/dashboard` },
                        { label: '', separator: true },
                        statusAction(r),
                    ]} />
            </div>
            {selected && (
                <PropertyPanel endpoint={`/web-api/admin/properties/${selected}`} editUrl={`/admin/properties/${selected}/edit`}
                    onClose={() => select(null)}
                    actions={(p: PropertyDetail) => (
                        <>
                            <LinkButton variant="outline" icon="bed-double" href={`/p/${p.code}/rooms`}>{t('property.manage_rooms')}</LinkButton>
                            <LinkButton variant="outline" icon="tags" href={`/p/${p.code}/rate-plans`}>{t('property.rate_plans')}</LinkButton>
                            <LinkButton variant="outline" icon="calendar-check" href={`/p/${p.code}/dashboard`}>{t('property.open_property')}</LinkButton>
                            <LinkButton variant="outline" icon="settings" href={`/admin/properties/${p.code}/edit`}>{t('property.property_settings')}</LinkButton>
                            <Button variant="outline" icon="copy" onClick={() => setCopying(p)}>{t('property.copy_property')}</Button>
                            {p.status === 'active'
                                ? <Button variant="danger-soft" icon="pause" onClick={() => setChange({ code: p.code, name: p.name, status: 'inactive' })}>{t('property.deactivate')}</Button>
                                : <Button variant="outline" icon="check-circle" onClick={() => setChange({ code: p.code, name: p.name, status: 'active' })}>{t('property.activate')}</Button>}
                        </>
                    )} />
            )}
            <ConfirmDialog open={!!change} busy={busy} danger={change?.status === 'inactive'}
                title={change?.status === 'inactive' ? t('property.deactivate') : t('property.activate')}
                message={change ? t(change.status === 'inactive' ? 'property.deactivate_confirm' : 'property.activate_confirm', { name: change.name }) : ''}
                confirmLabel={change?.status === 'inactive' ? t('property.deactivate') : t('property.activate')}
                onConfirm={applyStatus} onClose={() => setChange(null)} />
            <ConfirmDialog open={!!bulk} busy={busy} danger={bulk !== 'active'} title={t('admin.bulk_actions')}
                message={bulk ? t('admin.bulk_confirm', { n: checked.size, status: t(`ui.status.${bulk}`) }) : ''}
                confirmLabel={t('ui.confirm')} onConfirm={applyBulk} onClose={() => setBulk(null)} />
            <CopyPropertyDialog open={!!copying} endpoint={`/web-api/admin/properties/${copying?.code ?? ''}/copy`} sourceName={copying?.name ?? ''} onClose={() => setCopying(null)} />
        </div>
    );
}

createPage(AdminPropertiesPage);
