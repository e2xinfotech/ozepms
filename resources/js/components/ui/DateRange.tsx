import { useState } from 'react';
import { date } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Button } from './Button';
import { Input } from './Form';
import { Icon } from './Icon';
import { Dropdown } from './Overlay';

/** "01 Oct 2026 – 14 Oct 2026" button that opens a from/to picker. Dates are Y-m-d strings. */
export function DateRange({ from, to, onApply, maxDays }: { from: string; to: string; onApply: (from: string, to: string) => void; maxDays?: number }) {
    const [f, setF] = useState(from);
    const [tt, setT] = useState(to);
    const days = f && tt ? Math.round((new Date(tt).getTime() - new Date(f).getTime()) / 86400000) : 0;
    const invalid = !f || !tt || days < 0 || (maxDays !== undefined && days > maxDays);

    return (
        <Dropdown width={300} trigger={(toggle) => (
            <button type="button" className="btn btn-secondary" onClick={toggle} title={t('ui.date_range')}>
                <Icon name="calendar-days" size={18} />
                <span className="num">{date(from)} – {date(to)}</span>
                <Icon name="chevron-down" size={16} />
            </button>
        )} items={
            <div className="stack" style={{ padding: 10, gap: 12 }} onMouseDown={(e) => e.stopPropagation()}>
                <Input label={t('ui.from_date')} type="date" value={f} onChange={(e) => setF(e.target.value)} />
                <Input label={t('ui.to_date')} type="date" value={tt} min={f} onChange={(e) => setT(e.target.value)}
                    error={maxDays !== undefined && days > maxDays ? t('ui.range_too_long', { n: maxDays }) : null} />
                <Button variant="primary" block disabled={invalid} onClick={() => onApply(f, tt)}>{t('ui.apply')}</Button>
            </div>
        } />
    );
}
