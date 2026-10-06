import { Icon } from '@/components/ui';
import { t } from '@/lib/i18n';
import { barToneOf } from '@/lib/status';

const BARS = ['confirmed', 'in_house', 'pending', 'blocked', 'out_of_service'];

/** Colour key from the design: reservation bar statuses, then inventory and restriction markers. */
export function Legend() {
    const item = (key: string, swatch: React.ReactNode) => {
        const label = t(`calendar.legend.${key}`);
        return <li key={key} title={label}>{swatch}<span>{label}</span></li>;
    };
    return (
        <ul className="cal-legend" aria-label={t('calendar.title')}>
            {BARS.map((s) => item(s, <i className={`swatch tone-${barToneOf(s)}`} aria-hidden="true" />))}
            <li className="sep" aria-hidden="true" />
            {item('inventory_zero', <i className="swatch zero" aria-hidden="true" />)}
            {item('inventory_open', <i className="swatch open" aria-hidden="true" />)}
            {item('stop_sell', <i className="swatch stop" aria-hidden="true" />)}
            {item('cta', <Icon name="door-closed" size={16} className="mark" />)}
            {item('ctd', <Icon name="log-out" size={16} className="mark" />)}
            {item('cutoff', <i className="swatch cutoff" aria-hidden="true" />)}
        </ul>
    );
}
