import { Icon, PageHeader } from '@/components/ui';
import type { CatalogItem } from '@/components/reports/types';
import { createPage } from '@/lib/boot';
import { t } from '@/lib/i18n';

const GROUPS = ['performance', 'bookings', 'finance', 'operations'];

/** Reports hub: every report of the property, grouped. */
function ReportsIndex({ reports }: { reports: CatalogItem[] }) {
    return (
        <div className="content">
            <PageHeader title={t('reports.title')} description={t('reports.subtitle')} />
            {GROUPS.map((g) => {
                const items = reports.filter((r) => r.group === g);
                if (items.length === 0) return null;
                return (
                    <section key={g} className="report-group">
                        <h2 className="report-group-title">{t(`reports.groups.${g}`)}</h2>
                        <div className="report-cards">
                            {items.map((r) => (
                                <a key={r.key} className="report-card card" href={r.url}>
                                    <span className={`kpi-icon tone-${toneOf(g)}`}><Icon name={r.icon} size={22} /></span>
                                    <span className="grow">
                                        <span className="report-card-title">{r.title}</span>
                                        <span className="report-card-text">{r.description}</span>
                                    </span>
                                    <Icon name="chevron-right" size={18} />
                                </a>
                            ))}
                        </div>
                    </section>
                );
            })}
        </div>
    );
}

function toneOf(group: string): string {
    return ({ performance: 'blue', bookings: 'green', finance: 'amber', operations: 'purple' } as Record<string, string>)[group] ?? 'blue';
}

createPage(ReportsIndex);
