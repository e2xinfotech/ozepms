import { LinkButton, PageHeader } from '@/components/ui';
import { PropertyFilters, PropertyKpiRow, PropertyTable, PropertyStatusTabs, type PropertyListProps } from '@/components/property/PropertyList';
import { PropertyPanel } from '@/components/property/PropertyPanel';
import { createPage } from '@/lib/boot';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { useQueryState } from '@/lib/use';

interface Props extends PropertyListProps { selected: string; can_create: boolean }

/** Properties the signed-in user can open, with the details panel on the right. */
function PropertiesPage(props: Props) {
    const [selected, setSelected] = useQueryState('selected', props.selected || props.rows[0]?.code || '');

    return (
        <div className={selected ? 'content with-panel' : 'content'}>
            <div className="content-main">
                <PageHeader title={t('nav.properties')} description={t('property.list_sub')}
                    actions={props.can_create && <LinkButton variant="primary" icon="plus" href="/properties/new">{t('admin.add_property')}</LinkButton>} />
                <PropertyFilters filters={props.filters} options={props.options} />
                <PropertyKpiRow kpis={props.kpis} />
                <PropertyStatusTabs counts={props.counts} active={props.filters.status} statuses={props.options.statuses} />
                <PropertyTable rows={props.rows} meta={props.meta} selected={selected} onSelect={(r) => setSelected(r.code)}
                    menu={(r) => [
                        { label: t('ui.view'), icon: 'eye', onClick: () => setSelected(r.code) },
                        { label: t('property.open_property'), icon: 'arrow-right', href: `/p/${r.code}/dashboard` },
                        { label: t('property.property_settings'), icon: 'settings', href: `/p/${r.code}/settings` },
                    ]} />
            </div>
            {selected && (
                <PropertyPanel endpoint={propertyApiUrl(`/properties/${selected}`)} editUrl={`/p/${selected}/settings`} onClose={() => setSelected(null)}
                    actions={(p) => (
                        <>
                            <LinkButton variant="outline" icon="bed-double" href={`/p/${p.code}/rooms`}>{t('property.manage_rooms')}</LinkButton>
                            <LinkButton variant="outline" icon="tags" href={`/p/${p.code}/rate-plans`}>{t('property.rate_plans')}</LinkButton>
                            <LinkButton variant="outline" icon="arrow-right" href={`/p/${p.code}/dashboard`}>{t('property.open_property')}</LinkButton>
                            <LinkButton variant="outline" icon="settings" href={`/p/${p.code}/settings`}>{t('property.property_settings')}</LinkButton>
                        </>
                    )} />
            )}
        </div>
    );
}

createPage(PropertiesPage);
