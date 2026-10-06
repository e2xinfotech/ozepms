import clsx from 'clsx';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';
import { toneOf, type Tone } from '@/lib/status';
import { Icon } from './Icon';

/** Coloured status label. Pass a status key ("confirmed") or an explicit tone. */
export function Badge({ status, tone, children, size, dot }: { status?: string; tone?: Tone; children?: ReactNode; size?: 'sm'; dot?: boolean }) {
    const resolved = tone ?? toneOf(status);
    const label = children ?? (status ? t(`ui.status.${status}`) : '');
    return <span className={clsx('badge', `tone-${resolved}`, size)}>{dot && <span className="dot" />}{label}</span>;
}

export function Avatar({ name, initials, src, size }: { name?: string; initials?: string; src?: string | null; size?: 'sm' | 'lg' }) {
    const text = initials ?? (name ? name.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase() : '?');
    return <span className={clsx('avatar', size)} title={name}>{src ? <img src={src} alt="" /> : text}</span>;
}

/** Country flag from an ISO-2 code (uses the flag-icons SVG set). */
export function Flag({ code, large }: { code?: string | null; large?: boolean }) {
    if (!code) return null;
    return <span className={clsx('fi', `fi-${code.toLowerCase()}`, large && 'flag-lg')} aria-hidden />;
}

export function KpiCard({ icon, tone = 'blue', label, value, sub, change, compact, active, onClick, title, fit }: {
    icon: string; tone?: Tone; label: ReactNode; value: ReactNode; sub?: ReactNode; change?: number | null; title?: string; fit?: boolean;
    compact?: boolean; active?: boolean; onClick?: () => void;
}) {
    const Tag = onClick ? 'button' : 'div';
    return (
        <Tag className={clsx('kpi', compact && 'compact', onClick && 'button', active && 'active', fit && 'kpi-fit')} onClick={onClick} type={onClick ? 'button' : undefined}>
            <span className={clsx('kpi-icon', `tone-${tone}`)}><Icon name={icon} size={compact ? 18 : 26} /></span>
            <span className="grow">
                {!compact && <span className="kpi-label" style={{ display: 'block' }} title={typeof label === 'string' ? label : undefined}>{label}</span>}
                <span className="kpi-value num" title={title}>
                    {value}
                    {change !== undefined && change !== null && <Change value={change} />}
                </span>
                {compact && <span className="kpi-label" style={{ display: 'block' }}>{label}</span>}
                {sub && <span className="kpi-sub" style={{ display: 'block' }} title={typeof sub === 'string' ? sub : undefined}>{sub}</span>}
            </span>
        </Tag>
    );
}

export function Change({ value }: { value: number }) {
    const dir = value > 0 ? 'up' : value < 0 ? 'down' : 'flat';
    return (
        <span className={clsx('change', dir)}>
            {dir === 'up' && '↑'}{dir === 'down' && '↓'} {Math.abs(value)}%
        </span>
    );
}

export function Card({ title, actions, children, flush, className }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; flush?: boolean; className?: string }) {
    return (
        <section className={clsx('card', flush && 'flush', className)}>
            {(title || actions) && <div className="card-head"><h3>{title}</h3>{actions}</div>}
            <div className="card-body">{children}</div>
        </section>
    );
}

export function PageHeader({ title, description, actions, back }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; back?: string }) {
    return (
        <div className="page-header">
            <div className="row" style={{ alignItems: 'flex-start', gap: 16 }}>
                {back && <a className="page-back" href={back} aria-label={t('ui.back')}><Icon name="arrow-left" size={20} /></a>}
                <div>
                    <h1>{title}</h1>
                    {description && <p className="page-desc">{description}</p>}
                </div>
            </div>
            {actions && <div className="page-actions">{actions}</div>}
        </div>
    );
}

export function EmptyState({ icon = 'info', title, text, action }: { icon?: string; title: ReactNode; text?: ReactNode; action?: ReactNode }) {
    return (
        <div className="empty">
            <span className="empty-icon"><Icon name={icon} size={24} /></span>
            <h3>{title}</h3>
            {text && <p>{text}</p>}
            {action && <div style={{ marginTop: 10 }}>{action}</div>}
        </div>
    );
}

export function KeyValue({ items, wide }: { items: { label: ReactNode; value: ReactNode; icon?: string }[]; wide?: boolean }) {
    return (
        <dl className={clsx('kv', wide && 'wide')}>
            {items.map((item, i) => (
                <div key={i} style={{ display: 'contents' }}>
                    <dt>{item.icon && <Icon name={item.icon} size={16} />}{item.label}</dt>
                    <dd>{item.value ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

export function Alert({ tone = 'info', children, icon }: { tone?: 'info' | 'warn' | 'danger' | 'success'; children: ReactNode; icon?: string }) {
    const icons = { info: 'info', warn: 'alert-triangle', danger: 'circle-alert', success: 'check-circle' };
    return <div className={clsx('alert', tone)} role={tone === 'danger' ? 'alert' : undefined}><Icon name={icon ?? icons[tone]} size={18} /><div>{children}</div></div>;
}

export function Progress({ value, tone }: { value: number; tone?: 'amber' | 'red' | 'blue' }) {
    const v = Math.max(0, Math.min(100, value));
    const auto = tone ?? (v < 40 ? 'red' : v < 65 ? 'amber' : undefined);
    return <div className={clsx('progress', auto)} role="progressbar" aria-valuenow={v} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${v}%` }} /></div>;
}

export function Tooltip({ text, children }: { text: string; children: ReactNode }) {
    return <span className="tooltip-wrap" title={text}>{children}<span className="tooltip" role="tooltip">{text}</span></span>;
}

export function Stars({ count }: { count?: number | null }) {
    if (!count) return null;
    return <span className="stars" title={`${count} ★`}>{Array.from({ length: count }, (_, i) => <Icon key={i} name="star" size={16} strokeWidth={0} className="star-fill" />)}</span>;
}
