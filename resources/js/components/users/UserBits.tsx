import type { ReactNode } from 'react';
import { Avatar, Badge, EmptyState, Icon } from '@/components/ui';
import { relative } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { Tone } from '@/lib/status';

export interface ActivityItem { id: string; action: string; action_label: string; property: string | null; at: string | null; user?: string | null }
export interface PermissionGroup { module: string; label: string; permissions: { key: string; label: string }[] }

/** Avatar + name + job title cell used in user tables. */
export function UserCell({ name, title, avatar }: { name: string; title?: string | null; avatar?: string | null }) {
    return (
        <div className="media-cell" style={{ gap: 12 }}>
            <Avatar name={name} src={avatar} />
            <div><div className="cell-main">{name}</div>{title && <div className="cell-sub">{title}</div>}</div>
        </div>
    );
}

export function RoleBadge({ name, color }: { name: string | null; color: string | null }) {
    if (!name) return <span className="muted">—</span>;
    return <Badge tone={(color ?? 'slate') as Tone}>{name}</Badge>;
}

/** Header block of the user detail panels. */
export function UserHeader({ name, avatar, status, roleName, roleDescription, action }: {
    name: string; avatar: string | null; status: string; roleName: string | null; roleDescription: string | null; action?: ReactNode;
}) {
    return (
        <div className="sp-section" style={{ borderTop: 0, display: 'flex', gap: 16, alignItems: 'flex-start' }}>
            <Avatar name={name} src={avatar} size="lg" />
            <div className="grow stack" style={{ gap: 6 }}>
                <div className="row-between"><Badge status={status} dot />{action}</div>
                {roleName && <div className="strong">{roleName}</div>}
                {roleDescription && <p className="muted text-sm">{roleDescription}</p>}
            </div>
        </div>
    );
}

export function ActivityList({ items }: { items: ActivityItem[] }) {
    if (items.length === 0) return <EmptyState icon="history" title={t('users.no_activity')} />;
    return (
        <ul className="list-plain">
            {items.map((a) => (
                <li key={a.id} className="activity">
                    <span className="a-icon tone-blue"><Icon name="history" size={16} /></span>
                    <div className="grow"><div className="a-title">{a.action_label}</div>{a.property && <div className="a-sub">{a.property}</div>}</div>
                    <span className="a-time" title={a.at ?? ''}>{relative(a.at)}</span>
                </li>
            ))}
        </ul>
    );
}

/** Read-only view of granted permissions, grouped by module. */
export function PermissionView({ catalogue, granted }: { catalogue: PermissionGroup[]; granted: string[] }) {
    const set = new Set(granted);
    return (
        <div className="stack" style={{ gap: 12 }}>
            {catalogue.map((g) => {
                const held = g.permissions.filter((p) => set.has(p.key));
                return (
                    <div key={g.module} className="perm-group">
                        <h4>{g.label} <span className="muted text-sm">({held.length}/{g.permissions.length})</span></h4>
                        <div className="perm-tags">
                            {g.permissions.map((p) => <Badge key={p.key} size="sm" tone={set.has(p.key) ? 'green' : 'slate'}>{set.has(p.key) ? '✓ ' : ''}{p.label}</Badge>)}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
