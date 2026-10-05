import { useState, type ReactNode } from 'react';
import { Icon } from '@/components/ui';
import { date } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { ShellData } from '@/lib/page';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

export function AppLayout({ shell, children }: { shell: ShellData; children: ReactNode }) {
    const [menuOpen, setMenuOpen] = useState(false);
    const sub = shell.property?.subscription;

    return (
        <div className="app">
            <Sidebar shell={shell} open={menuOpen} />
            <div className="main">
                <Topbar shell={shell} onMenu={() => setMenuOpen((o) => !o)} />
                {shell.support_mode && (
                    <div className="banner info"><Icon name="shield-check" size={16} />{t('nav.support_mode_banner')}</div>
                )}
                {sub && sub.warning && (
                    <div className={`banner ${sub.read_only ? 'danger' : 'warn'}`}>
                        <Icon name="alert-triangle" size={16} />
                        {sub.read_only
                            ? t('subscription.banner_read_only')
                            : t('subscription.banner_expiring', { date: date(sub.ends_on), days: sub.days_left ?? 0 })}
                    </div>
                )}
                {children}
            </div>
        </div>
    );
}
