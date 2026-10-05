import type { ReactNode } from 'react';
import { Dropdown, Icon } from '@/components/ui';
import { http } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { ShellData } from '@/lib/page';
import { Logo } from './Logo';

const features = [
    ['calendar-check', 'auth.hero.reservations'], ['tags', 'auth.hero.rate_plans'],
    ['bed-double', 'auth.hero.rooms'], ['network', 'auth.hero.channels'],
    ['chart-column', 'auth.hero.reports'], ['settings', 'auth.hero.multi_property'],
];

export function AuthLayout({ shell, children }: { shell: ShellData; children: ReactNode }) {
    const current = document.documentElement.lang.slice(0, 2);
    return (
        <div className="auth">
            <section className="auth-hero" style={shell.brand.auth_image ? { ['--auth-image' as string]: `url(${shell.brand.auth_image})` } : undefined}>
                <div>
                    <Logo />
                    <h2>{t('auth.hero.title_1')}<br />{t('auth.hero.title_2')}</h2>
                    <p className="lead">{t('auth.hero.lead')}</p>
                    <div className="auth-features">
                        {features.map(([icon, key]) => <div key={key} className="auth-feature"><i><Icon name={icon} size={22} /></i>{t(key)}</div>)}
                    </div>
                </div>
                <div className="auth-stats">
                    <div className="auth-stat"><b>{t('auth.hero.stat_1_value')}</b>{t('auth.hero.stat_1_label')}</div>
                    <div className="auth-stat"><b>{t('auth.hero.stat_2_value')}</b>{t('auth.hero.stat_2_label')}</div>
                    <div className="auth-stat"><b>{t('auth.hero.stat_3_value')}</b>{t('auth.hero.stat_3_label')}</div>
                </div>
            </section>
            <section className="auth-side">
                <div className="lang">
                    <Dropdown width={180} trigger={(toggle) => (
                        <button className="btn btn-secondary" onClick={toggle}><Icon name="globe" size={18} />{shell.locales[current] ?? 'English'}<Icon name="chevron-down" size={16} /></button>
                    )} items={Object.entries(shell.locales).map(([code, name]) => ({
                        label: name, active: code === current,
                        onClick: async () => { await http.post('/web-api/locale', { locale: code }); window.location.reload(); },
                    }))} />
                </div>
                <div className="auth-card">{children}</div>
                <p className="text-xs muted" style={{ textAlign: 'center', marginTop: 16 }}>© {new Date().getFullYear()} {shell.brand.company}</p>
            </section>
        </div>
    );
}
