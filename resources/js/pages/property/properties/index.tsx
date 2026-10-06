import { useEffect, useState } from 'react';
import { Button, LinkButton, PageHeader } from '@/components/ui';
import { CopyPropertyDialog } from '@/components/property/CopyPropertyDialog';
import { PropertyFilters, PropertyKpiRow, PropertyTable, PropertyStatusTabs, type PropertyListProps } from '@/components/property/PropertyList';
import { PropertyPanel } from '@/components/property/PropertyPanel';
import { createPage } from '@/lib/boot';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyCode } from '@/lib/page';
import { useQueryState } from '@/lib/use';

interface Props extends PropertyListProps { selected: string; can_create: boolean; can_copy: boolean }

/** Properties the signed-in user can open, with the details panel on the right. */
function PropertiesPage(props: Props) {
    const [selected, setSelected] = useQueryState('selected', props.selected || props.rows[0]?.code || '');
    const [copying, setCopying] = useState(false);
    const current = propertyCode();

    // "?copy=1" opens the copy dialog straight away (used when copying another property from here).
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('copy') === '1' && props.can_copy) setCopying(true);
    }, [props.can_copy]);

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
                            <LinkButton variant="outline" icon="settings" href={`/p/${p.code}/settings`}>{t('property.property_settings')}</LinkButton>
                            {p.code === current
                                ? props.can_copy && <Button variant="outline" icon="copy" onClick={() => setCopying(true)}>{t('property.copy_property')}</Button>
                                : <LinkButton variant="outline" icon="copy" href={`/p/${p.code}/properties?selected=${p.code}&copy=1`}>{t('property.copy_property')}</LinkButton>}
                        </>
                    )} />
            )}
            <CopyPropertyDialog open={copying} endpoint={propertyApiUrl('/copy')} sourceName={props.rows.find((r) => r.code === current)?.name ?? ''} onClose={() => setCopying(false)} />
        </div>
    );
}

createPage(PropertiesPage);
