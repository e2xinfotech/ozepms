import clsx from 'clsx';
import type { ReactNode } from 'react';
import { t } from '@/lib/i18n';
import { navigateWithQuery, query } from '@/lib/http';
import { Icon } from './Icon';
import { EmptyState } from './Display';

export interface Column<R> {
    key: string;
    header: ReactNode;
    render: (row: R) => ReactNode;
    sortable?: boolean;
    align?: 'right' | 'center';
    width?: number | string;
    className?: string;
}

/**
 * Data table. Sorting writes ?sort=key&dir=asc to the URL (server-side sorting).
 */
export function DataTable<R>({ columns, rows, rowKey, onRowClick, selectedKey, selectable, selected, onSelect, empty }: {
    columns: Column<R>[];
    rows: R[];
    rowKey: (row: R) => string;
    onRowClick?: (row: R) => void;
    selectedKey?: string | null;
    selectable?: boolean;
    selected?: Set<string>;
    onSelect?: (keys: Set<string>) => void;
    empty?: ReactNode;
}) {
    const sort = query('sort');
    const dir = query('dir') || 'asc';
    const allSelected = selectable && rows.length > 0 && rows.every((r) => selected?.has(rowKey(r)));

    const toggleAll = () => {
        if (!onSelect) return;
        onSelect(allSelected ? new Set() : new Set(rows.map(rowKey)));
    };
    const toggle = (key: string) => {
        if (!onSelect || !selected) return;
        const next = new Set(selected);
        if (next.has(key)) next.delete(key); else next.add(key);
        onSelect(next);
    };

    return (
        <div className="table-wrap">
            <div className="table-scroll">
                <table className="table">
                    <thead>
                        <tr>
                            {selectable && <th className="col-check"><input type="checkbox" checked={!!allSelected} onChange={toggleAll} aria-label={t('ui.select_all')} /></th>}
                            {columns.map((c) => (
                                <th key={c.key} style={{ width: c.width, textAlign: c.align }} className={clsx(c.sortable && 'sortable', sort === c.key && 'sorted', c.align === 'right' && 'num', c.className)}
                                    onClick={c.sortable ? () => navigateWithQuery({ sort: c.key, dir: sort === c.key && dir === 'asc' ? 'desc' : 'asc' }) : undefined}>
                                    {c.header}
                                    {c.sortable && <span className="sort"><Icon name={sort === c.key ? (dir === 'asc' ? 'chevron-up' : 'chevron-down') : 'arrow-up-down'} size={13} /></span>}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => {
                            const key = rowKey(row);
                            return (
                                <tr key={key} className={clsx(onRowClick && 'clickable', selectedKey === key && 'selected')} onClick={onRowClick ? () => onRowClick(row) : undefined}>
                                    {selectable && (
                                        <td className="col-check" onClick={(e) => e.stopPropagation()}>
                                            <input type="checkbox" checked={!!selected?.has(key)} onChange={() => toggle(key)} aria-label={t('ui.select_row')} />
                                        </td>
                                    )}
                                    {columns.map((c) => (
                                        <td key={c.key} style={{ textAlign: c.align }} className={clsx(c.align === 'right' && 'num', c.className)}>{c.render(row)}</td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {rows.length === 0 && (empty ?? <EmptyState icon="search" title={t('ui.no_results')} text={t('ui.no_results_hint')} />)}
        </div>
    );
}

export interface PageMeta { page: number; per_page: number; total: number; last_page: number }

/** "Showing 1–10 of 156" + page buttons + per-page selector. Updates the URL. */
export function Pagination({ meta, label, options = [10, 20, 50, 100] }: { meta: PageMeta; label?: string; options?: number[] }) {
    const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.per_page + 1;
    const to = Math.min(meta.total, meta.page * meta.per_page);
    const pages = pageList(meta.page, meta.last_page);

    return (
        <div className="table-foot">
            <span>{t('ui.showing', { from, to, total: meta.total, items: label ?? t('ui.items') })}</span>
            {meta.last_page > 1 && (
                <nav className="pagination" aria-label="Pagination">
                    <button className="page-btn" disabled={meta.page <= 1} onClick={() => navigateWithQuery({ page: meta.page - 1 }, false)} aria-label={t('ui.previous')}><Icon name="chevron-left" size={16} /></button>
                    {pages.map((p, i) => p === '…'
                        ? <span key={`gap${i}`} className="muted">…</span>
                        : <button key={p} className={clsx('page-btn', p === meta.page && 'active')} onClick={() => navigateWithQuery({ page: p }, false)}>{p}</button>)}
                    <button className="page-btn" disabled={meta.page >= meta.last_page} onClick={() => navigateWithQuery({ page: meta.page + 1 }, false)} aria-label={t('ui.next')}><Icon name="chevron-right" size={16} /></button>
                </nav>
            )}
            <label className="per-page">
                <span>{t('ui.show')}</span>
                <span className="control">
                    <select value={meta.per_page} onChange={(e) => navigateWithQuery({ per_page: e.target.value })}>
                        {options.map((o) => <option key={o} value={o}>{t('ui.per_page', { n: o })}</option>)}
                    </select>
                    <Icon name="chevron-down" size={16} className="control-chevron" />
                </span>
            </label>
        </div>
    );
}

function pageList(current: number, last: number): (number | '…')[] {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const out: (number | '…')[] = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);
    if (start > 2) out.push('…');
    for (let p = start; p <= end; p++) out.push(p);
    if (end < last - 1) out.push('…');
    out.push(last);
    return out;
}
