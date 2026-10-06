import { GlobalSearch } from './GlobalSearch';
import { Avatar, Dropdown, Icon } from '@/components/ui';
import { http } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { ShellData } from '@/lib/page';

function switchUrl(code: string): string {
    // Stay on the same section when switching property: /p/P1001/users → /p/P1002/users
    const match = window.location.pathname.match(/^\/p\/[^/]+(\/[^/]*)?/);
    const section = match?.[1] && match[1] !== '/' ? match[1] : '/dashboard';
    return `/p/${code}${section}`;
}

async function changeLocale(locale: string) {
    await http.post('/web-api/locale', { locale });
    window.location.reload();
}

async function logout() {
    await http.post('/web-api/auth/logout');
    window.location.href = '/login';
}

export function Topbar({ shell, onMenu }: { shell: ShellData; onMenu: () => void }) {
    const property = shell.property;
    const user = shell.user!;

    return (
        <header className="topbar">
            <button className="topbar-btn" style={{ display: 'none' }} onClick={onMenu} aria-label={t('nav.menu')}><Icon name="menu" size={20} /></button>

            {property ? (
                <Dropdown align="left" width={320} trigger={(toggle) => (
                    <button className="prop-switch" onClick={toggle} aria-haspopup="menu">
                        {property.image ? <img className="prop-thumb" src={property.image} alt="" /> : <span className="prop-thumb"><Icon name="hotel" size={24} /></span>}
                        <span className="grow">
                            <span className="prop-name">{property.name}<Icon name="chevron-down" size={18} /></span>
                            <span className="prop-meta">{property.code}{property.location && <>&nbsp;&nbsp;|&nbsp;&nbsp;{property.location}</>}</span>
                        </span>
                    </button>
                )} items={
                    <>
                        <div className="menu-title">{t('nav.switch_property')}</div>
                        {(shell.properties ?? []).map((p) => (
                            <a key={p.code} href={switchUrl(p.code)} className={`menu-item${p.code === property.code ? ' active' : ''}`}>
                                <Icon name="hotel" size={16} />
                                <span className="grow"><span className="strong">{p.name}</span><br /><span className="text-xs muted">{p.code} · {p.location}</span></span>
                                {p.code === property.code && <Icon name="check" size={16} />}
                            </a>
                        ))}
                        {shell.support_mode && <div className="menu-item muted">{t('nav.support_mode_hint')}</div>}
                        <div className="menu-sep" />
                        <a className="menu-item" href="/properties"><Icon name="building-2" size={16} />{t('nav.all_properties')}</a>
                    </>
                } />
            ) : (
                <a className="prop-switch" href="/home">
                    <span className="prop-thumb"><Icon name={shell.is_platform ? 'shield-check' : 'building-2'} size={24} /></span>
                    <span><span className="prop-name">{shell.brand.name}</span><span className="prop-meta">{shell.is_platform ? t('nav.platform_admin') : t('nav.your_properties')}</span></span>
                </a>
            )}

            {property && <GlobalSearch placeholder={t('nav.search_property')} />}
            {!property && shell.is_platform && <div className="topbar-search">
                <form className="control" role="search" action="/admin/properties" method="get">
                    <Icon name="search" size={18} className="control-icon" />
                    <input type="search" name="q" maxLength={100} placeholder={t('nav.search_platform')} aria-label={t('ui.search')} />
                </form>
            </div>}

            <div className="topbar-actions">
                <Dropdown width={180} trigger={(toggle) => (
                    <button className="topbar-btn" onClick={toggle} aria-label={t('nav.language')}><Icon name="globe" size={20} />{(document.documentElement.lang || 'en').slice(0, 2).toUpperCase()}</button>
                )} items={Object.entries(shell.locales).map(([code, name]) => ({
                    label: name, onClick: () => changeLocale(code), active: document.documentElement.lang.startsWith(code),
                }))} />

                <Dropdown width={300} trigger={(toggle) => (
                    <button className="topbar-btn" onClick={toggle} aria-label={t('nav.notifications')}><Icon name="bell" size={21} /></button>
                )} items={<div className="empty" style={{ padding: 24 }}><Icon name="bell" size={22} /><span>{t('nav.no_notifications')}</span></div>} />

                <Dropdown width={240} trigger={(toggle) => (
                    <button className="user-chip" onClick={toggle} aria-haspopup="menu">
                        <Avatar name={user.name} initials={user.initials} src={user.avatar} />
                        <span><span className="user-name" style={{ display: 'block' }}>{user.name}</span><span className="user-role">{user.role ?? ''}</span></span>
                        <Icon name="chevron-down" size={18} />
                    </button>
                )} items={[
                    { label: t('nav.profile'), icon: 'user', href: '/account/profile' },
                    { label: t('nav.security'), icon: 'shield', href: '/account/security' },
                    ...(shell.admin_url ? [{ label: t('nav.super_admin'), icon: 'shield-check', href: shell.admin_url }] : []),
                    { label: '', separator: true },
                    { label: t('nav.sign_out'), icon: 'log-out', onClick: logout, danger: true },
                ]} />
            </div>
        </header>
    );
}
