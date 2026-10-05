import { Badge, Icon, Tooltip } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { PolicyRule } from './types';

export function PolicyBadge({ refundable }: { refundable: boolean | undefined }) {
    if (refundable === undefined) return <span className="muted">—</span>;
    return <Badge size="sm" status={refundable ? 'flexible' : 'non_refundable'}>{refundable ? t('rates.policy.refundable') : t('rates.policy.non_refundable')}</Badge>;
}

/** Where the plan is sold: front desk, booking engine, OTA channels (greyed out when off). */
export function ChannelIcons({ channels }: { channels: { pms: boolean; booking_engine: boolean; channels: boolean } }) {
    const items: [keyof typeof channels, string][] = [['pms', 'concierge-bell'], ['booking_engine', 'globe'], ['channels', 'network']];
    return (
        <span className="channel-icons">
            {items.map(([key, icon]) => {
                const label = `${t(`rates.channels.${key}`)}: ${channels[key] ? t('ui.yes') : t('ui.no')}`;
                return <Tooltip key={key} text={label}><span className={channels[key] ? undefined : 'off'} title={label}><Icon name={icon} size={17} /></span></Tooltip>;
            })}
        </span>
    );
}

/** "First night if cancelled less than 24 h before arrival" lines. */
export function PolicyRules({ rules, currency }: { rules: PolicyRule[]; currency: string }) {
    const charge = (r: PolicyRule) => {
        const label = t(`rates.policy.charge_types.${r.charge_type}`);
        if (r.charge_type === 'percent') return `${Number(r.charge_value)} %`;
        if (r.charge_type === 'fixed') return `${currency} ${r.charge_value}`;
        if (r.charge_type === 'nights') return nightsLabel(Number(r.charge_value));
        return label;
    };
    return (
        <ul className="policy-rules">
            {rules.map((r, i) => (
                <li key={i}>{r.applies_to === 'no_show'
                    ? t('rates.policy.no_show_text', { charge: charge(r) })
                    : t('rates.policy.rule_text', { charge: charge(r), hours: r.hours_before_arrival })}</li>
            ))}
        </ul>
    );
}

export function nightsLabel(n: number): string {
    return n === 1 ? t('rates.one_night') : t('rates.n_nights', { count: n });
}
