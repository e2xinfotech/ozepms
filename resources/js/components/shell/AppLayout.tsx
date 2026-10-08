import { useState, type ReactNode } from 'react';
import { Button, Icon } from '@/components/ui';
import { http } from '@/lib/http';
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
            {menuOpen && <div className="sidebar-backdrop" onClick={() => setMenuOpen(false)} aria-hidden="true" />}
            <div className="main">
                <Topbar shell={shell} onMenu={() => setMenuOpen((o) => !o)} />
                {shell.impersonation && (
                    <div className="banner impersonation">
                        <Icon name="user-cog" size={16} />
                        <span className="grow">
                            {t('impersonation.banner', { name: shell.impersonation.target })} · {t('impersonation.banner_by', { actor: shell.impersonation.actor ?? '' })} · {t('impersonation.minutes_left', { n: shell.impersonation.minutes_left })}
                        </span>
                        <Button size="sm" variant="outline" icon="log-out" onClick={async () => { const r = await http.post<{ redirect: string }>('/web-api/impersonation/stop'); window.location.href = r.redirect; }}>
                            {t('impersonation.return')}
                        </Button>
                    </div>
                )}
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
