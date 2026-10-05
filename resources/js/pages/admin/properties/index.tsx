import { useState } from 'react';
import { Badge, Button, ConfirmDialog, LinkButton, PageHeader, toast } from '@/components/ui';
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

/** Super Admin → Properties Management. */
function AdminPropertiesPage(props: Props) {
    const [selected, select] = useQueryState('selected', props.selected || props.rows[0]?.code || '');
    const [change, setChange] = useState<StatusChange | null>(null);
    const [busy, setBusy] = useState(false);

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

    const exportRows = () => downloadCsv('properties.csv',
        [t('property.code'), t('property.name'), t('property.location'), t('property.type'), t('property.total_rooms'), t('ui.status_label'), t('subscription.plan')],
        props.rows.map((r) => [r.code, r.name, r.location, r.type_label, r.rooms, t(`ui.status.${r.status}`), r.plan]));

    const statusAction = (r: Pick<PropertyRow, 'code' | 'name' | 'status'>) => r.status === 'active'
        ? { label: t('property.deactivate'), icon: 'pause', danger: true, onClick: () => setChange({ code: r.code, name: r.name, status: 'inactive' }) }
        : { label: t('property.activate'), icon: 'check-circle', onClick: () => setChange({ code: r.code, name: r.name, status: 'active' }) };

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('admin.properties_title')} description={t('admin.properties_sub')} actions={<>
                    <Button icon="download" onClick={exportRows} disabled={props.rows.length === 0}>{t('ui.export')}</Button>
                    <LinkButton variant="primary" icon="plus" href="/admin/properties/new">{t('admin.add_property')}</LinkButton>
                </>} />
                <PropertyKpiRow kpis={props.kpis} withRooms />
                <PropertyFilters filters={props.filters} options={props.options} />
                <PropertyStatusTabs counts={props.counts} active={props.filters.status} statuses={props.options.statuses} />
                <PropertyTable rows={props.rows} meta={props.meta} selected={selected} onSelect={(r) => select(r.code)}
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
        </div>
    );
}

createPage(AdminPropertiesPage);
