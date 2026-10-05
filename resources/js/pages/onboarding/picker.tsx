import { Badge, EmptyState, Icon, LinkButton, PageHeader } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { t } from '@/lib/i18n';

interface Item { code: string; name: string; status: string; role: string; location: string; url: string }

/** Property chooser for users who belong to several properties. */
function PickerPage({ properties }: { properties: Item[] }) {
    return (
        <div className="content">
            <PageHeader title={t('property.choose_title')} description={t('property.choose_sub')}
                actions={<LinkButton variant="primary" icon="plus" href="/properties/new">{t('property.create_title')}</LinkButton>} />
            {properties.length === 0 ? (
                <EmptyState icon="hotel" title={t('property.no_properties')} text={t('property.no_properties_hint')}
                    action={<LinkButton variant="primary" icon="plus" href="/properties/new">{t('property.create_button')}</LinkButton>} />
            ) : (
                <div className="properties-grid">
                    {properties.map((p) => (
                        <a key={p.code} href={p.url} className="property-card">
                            <div className="row-between">
                                <span className="kpi-icon tone-blue" style={{ width: 44, height: 44 }}><Icon name="hotel" size={22} /></span>
                                <Badge status={p.status} />
                            </div>
                            <div>
                                <div className="strong" style={{ fontSize: 17 }}>{p.name}</div>
                                <div className="muted text-sm">{p.code}{p.location && ` · ${p.location}`}</div>
                            </div>
                            <div className="row-between text-sm">
                                <span className="muted">{p.role}</span>
                                <span className="row strong" style={{ color: 'var(--brand-600)' }}>{t('property.open_property')}<Icon name="arrow-right" size={16} /></span>
                            </div>
                        </a>
                    ))}
                </div>
            )}
        </div>
    );
}

createPage(PickerPage);
