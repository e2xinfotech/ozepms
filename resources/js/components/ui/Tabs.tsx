import clsx from 'clsx';

export interface TabItem { key: string; label: string; count?: number }

/** Status tabs with count pills (list pages). */
export function PillTabs({ items, active, onChange }: { items: TabItem[]; active: string; onChange: (key: string) => void }) {
    return (
        <div className="pill-tabs" role="tablist">
            {items.map((item) => (
                <button key={item.key} type="button" role="tab" aria-selected={item.key === active} className={clsx('pill-tab', item.key === active && 'active')} onClick={() => onChange(item.key)}>
                    {item.label}
                    {item.count !== undefined && <span className="count-pill num">{item.count}</span>}
                </button>
            ))}
        </div>
    );
}

/** Underline tabs (detail panels). */
export function Tabs({ items, active, onChange }: { items: TabItem[]; active: string; onChange: (key: string) => void }) {
    return (
        <div className="tabs" role="tablist">
            {items.map((item) => (
                <button key={item.key} type="button" role="tab" aria-selected={item.key === active} className={clsx('tab', item.key === active && 'active')} onClick={() => onChange(item.key)}>
                    {item.label}{item.count !== undefined ? ` (${item.count})` : ''}
                </button>
            ))}
        </div>
    );
}

/** Boxed segmented switcher (chart metric, view mode). */
export function Segmented({ items, active, onChange }: { items: TabItem[]; active: string; onChange: (key: string) => void }) {
    return (
        <div className="seg" role="tablist">
            {items.map((item) => (
                <button key={item.key} type="button" className={clsx(item.key === active && 'active')} onClick={() => onChange(item.key)}>{item.label}</button>
            ))}
        </div>
    );
}

export function Stepper({ steps, current }: { steps: string[]; current: number }) {
    return (
        <div className="stepper">
            {steps.map((label, i) => (
                <div key={label} style={{ display: 'contents' }}>
                    {i > 0 && <span className="step-line" />}
                    <span className={clsx('step', i === current && 'active', i < current && 'done')}>
                        <span className="step-no">{i + 1}</span>{label}
                    </span>
                </div>
            ))}
        </div>
    );
}
