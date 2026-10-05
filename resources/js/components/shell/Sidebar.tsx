import clsx from 'clsx';
import { Icon } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { ShellData } from '@/lib/page';
import { Logo } from './Logo';

export function Sidebar({ shell, open }: { shell: ShellData; open: boolean }) {
    const inProperty = !!shell.property;
    return (
        <aside className={clsx('sidebar', open && 'open')}>
            <a href="/home" className="sidebar-logo" aria-label={shell.brand.name}><Logo /></a>
            <nav className="nav" aria-label={t('nav.main')}>
                {(shell.menu ?? []).map((item) => item.url && !item.phase ? (
                    <a key={item.key} href={item.url} className={clsx('nav-item', item.active && 'active')} aria-current={item.active ? 'page' : undefined}>
                        <Icon name={item.icon} size={21} />{item.label}
                    </a>
                ) : (
                    <span key={item.key} className="nav-item disabled" title={t('nav.coming_soon')} aria-disabled="true">
                        <Icon name={item.icon} size={21} />{item.label}
                        {item.phase && <span className="nav-soon">{t('nav.soon')}</span>}
                    </span>
                ))}
            </nav>
            <div className="nav-spacer" />
            {inProperty && shell.admin_url && (
                <nav className="nav">
                    <a href={shell.admin_url} className="nav-item"><Icon name="shield-check" size={21} />{t('nav.super_admin')}</a>
                </nav>
            )}
        </aside>
    );
}
