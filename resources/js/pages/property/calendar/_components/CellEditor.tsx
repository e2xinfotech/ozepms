import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Alert, Button, Icon } from '@/components/ui';
import { ApiError, http } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { useClickOutside } from '@/lib/use';
import { AriFields, blankValues, toPayload, type AriValues } from './AriFields';
import { dayLabel, fullDay } from './dates';
import type { Grid, Selection } from './types';

export interface SaveResult { message: string; result: { changed: number; skipped: { reason: string; label: string; dates: number }[] } }

interface Props {
    grid: Grid;
    selection: Selection;
    at: { x: number; y: number };
    onClose: () => void;
    onSaved: (res: SaveResult) => void;
}

const str = (n: number | null | undefined) => (n === null || n === undefined || n === 0 ? '' : String(n));

/**
 * Inline editor next to the selected cells. One night: fields start with the stored values and
 * only changes are sent (cell endpoint). Several nights: fields start empty and only filled values
 * are sent (range endpoint).
 */
export function CellEditor({ grid, selection, at, onClose, onSaved }: Props) {
    const rt = grid.room_types.find((r) => r.id === selection.roomTypeId)!;
    const product = selection.kind === 'product' ? rt.products.find((p) => p.id === selection.rowId) ?? null : null;
    const single = selection.start === selection.end;
    const from = grid.days[selection.start].date;
    const to = grid.days[selection.end].date;

    const initial = useMemo<AriValues | undefined>(() => {
        if (!single) return undefined;
        const v = blankValues();
        if (product) {
            const d = product.days[selection.start];
            v.price = product.pricing_mode === 'derived' ? '' : d.p ?? '';
            v.occ = { ...(d.o ?? {}) };
            v.min_los = str(d.min) || '1'; v.max_los = str(d.max) || '99'; v.min_advance = str(d.cut); v.max_advance = str(d.adv);
            v.cta = d.cta ? '1' : '0'; v.ctd = d.ctd ? '1' : '0'; v.closed = d.ss ? '1' : '0';
        } else {
            const d = rt.inventory[selection.start];
            v.stop_sell = d.ss ? '1' : '0';
            v.sell_limit = d.l === null ? '' : String(d.l);
        }
        return v;
    }, [single, product, rt, selection.start]);

    const [values, setValues] = useState<AriValues>(() => initial ? { ...initial, occ: { ...initial.occ } } : blankValues());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const ref = useClickOutside<HTMLDivElement>(onClose);
    const [pos, setPos] = useState({ left: at.x, top: at.y + 12 });
    const first = useRef<HTMLDivElement>(null);

    // Keep the card inside the window.
    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const w = el.offsetWidth, h = el.offsetHeight, m = 12;
        let left = Math.min(Math.max(m, at.x - w / 2), window.innerWidth - w - m);
        let top = at.y + 14;
        if (top + h > window.innerHeight - m) top = Math.max(m, at.y - h - 14);
        left = Math.max(m, left);
        setPos({ left, top });
    }, [at, ref]);

    useEffect(() => {
        first.current?.querySelector<HTMLInputElement | HTMLSelectElement>('input:not([disabled]), select:not([disabled])')?.focus();
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [onClose]);

    const occupancies = product
        ? Array.from({ length: rt.max_adults }, (_, i) => i + 1).filter((n) => n !== product.base_adults)
        : [];
    const rowName = product ? `${rt.code} · ${product.rate_plan.name} (${product.rate_plan.code})` : `${rt.name} (${rt.code})`;

    const save = async () => {
        const body = toPayload(values, initial);
        if (Object.keys(body).length === 0) { onClose(); return; }
        setBusy(true);
        setError(null);
        try {
            const target = product ? { product_id: product.id } : { room_type_id: rt.id };
            const res = single
                ? await http.put<SaveResult>(propertyApiUrl('/calendar/cell'), { date: from, ...target, ...body })
                : await http.put<SaveResult>(propertyApiUrl('/calendar/range'), {
                    date_from: from, date_to: to, ...(product ? { product_ids: [product.id] } : { room_type_ids: [rt.id] }), ...body,
                });
            onSaved(res);
        } catch (e) {
            setError(e instanceof ApiError ? e : new ApiError((e as Error).message, 0, 'CLIENT'));
        } finally {
            setBusy(false);
        }
    };

    const general = error && (error.field('fields') ?? error.field('targets') ?? error.field('products') ?? error.field('product_ids.0') ?? error.field('room_type_ids.0')
        ?? (Object.keys(error.fields).length === 0 ? error.message : null));

    return createPortal(
        <div className="cal-editor" ref={ref} role="dialog" aria-modal="false" aria-label={rowName} style={pos}
            onKeyDown={(e) => { if (e.key === 'Enter' && (e.target as HTMLElement).tagName === 'INPUT') { e.preventDefault(); void save(); } }}>
            <header>
                <div>
                    <b>{rowName}</b>
                    <small>{single ? fullDay(from) : `${dayLabel(from)} – ${fullDay(to)}`}</small>
                </div>
                <button type="button" className="icon-close" onClick={onClose} aria-label={t('ui.close')} title={t('ui.close')}><Icon name="x" size={18} /></button>
            </header>
            <div className="cal-editor-body" ref={first}>
                {general && <Alert tone="danger">{general}</Alert>}
                {product?.pricing_mode === 'derived' && <p className="field-hint">{t('calendar.edit.derived_hint', { parent: product.parent ?? '' })}</p>}
                {product?.inherits_restrictions && <p className="field-hint">{t('calendar.edit.inherit_hint')}</p>}
                <div className="form-grid">
                    <AriFields values={values} onChange={setValues} error={error} single={single} compact
                        roomType={!product} product={!!product} occupancies={occupancies}
                        priceLocked={product?.pricing_mode === 'derived'} restrictionsLocked={product?.inherits_restrictions} />
                </div>
                <p className="field-hint">{t('calendar.edit.empty_hint')}</p>
            </div>
            <footer>
                <Button variant="secondary" onClick={onClose}>{t('calendar.edit.cancel')}</Button>
                <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('calendar.edit.save')}</Button>
            </footer>
        </div>,
        document.body,
    );
}
