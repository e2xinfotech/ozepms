import clsx from 'clsx';
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { t } from '@/lib/i18n';
import { useClickOutside } from '@/lib/use';
import { Button } from './Button';
import { Icon } from './Icon';

function useEscape(onClose: () => void) {
    useEffect(() => {
        const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, [onClose]);
}

export function Modal({ open, title, onClose, children, footer, size }: { open: boolean; title: ReactNode; onClose: () => void; children: ReactNode; footer?: ReactNode; size?: 'lg' }) {
    useEscape(onClose);
    if (!open) return null;
    return createPortal(
        <div className="overlay center" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <div className={clsx('modal', size)} role="dialog" aria-modal="true">
                <header><h2>{title}</h2><button className="icon-close" onClick={onClose} aria-label={t('ui.close')}><Icon name="x" size={20} /></button></header>
                <div className="modal-body">{children}</div>
                {footer && <footer>{footer}</footer>}
            </div>
        </div>,
        document.body,
    );
}

/** Slide-over form panel from the right. */
export function Drawer({ open, title, onClose, children, footer, size }: { open: boolean; title: ReactNode; onClose: () => void; children: ReactNode; footer?: ReactNode; size?: 'lg' }) {
    useEscape(onClose);
    if (!open) return null;
    return createPortal(
        <div className="overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
            <aside className={clsx('drawer', size)} role="dialog" aria-modal="true">
                <header><h2>{title}</h2><button className="icon-close" onClick={onClose} aria-label={t('ui.close')}><Icon name="x" size={20} /></button></header>
                <div className="drawer-body">{children}</div>
                {footer && <footer>{footer}</footer>}
            </aside>
        </div>,
        document.body,
    );
}

export function ConfirmDialog({ open, title, message, confirmLabel, danger, busy, onConfirm, onClose }: {
    open: boolean; title: ReactNode; message: ReactNode; confirmLabel?: string; danger?: boolean; busy?: boolean; onConfirm: () => void; onClose: () => void;
}) {
    return (
        <Modal open={open} title={title} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant={danger ? 'danger' : 'primary'} loading={busy} onClick={onConfirm}>{confirmLabel ?? t('ui.confirm')}</Button>
        </>}>
            <p>{message}</p>
        </Modal>
    );
}

/** Right-hand detail panel used by master/detail list pages. */
export function SidePanel({ title, onClose, children, headerExtra }: { title: ReactNode; onClose?: () => void; children: ReactNode; headerExtra?: ReactNode }) {
    return (
        <aside className="side-panel">
            <div className="sp-head">
                <h2>{title}</h2>
                <div className="row">{headerExtra}{onClose && <button className="icon-close" onClick={onClose} aria-label={t('ui.close')}><Icon name="x" size={20} /></button>}</div>
            </div>
            {children}
        </aside>
    );
}

export interface MenuEntry { label: string; icon?: string; onClick?: () => void; href?: string; danger?: boolean; separator?: boolean; active?: boolean }

export function Dropdown({ trigger, items, align = 'right', width }: { trigger: (toggle: () => void, open: boolean) => ReactNode; items: MenuEntry[] | ReactNode; align?: 'left' | 'right'; width?: number }) {
    const [open, setOpen] = useState(false);
    const close = useCallback(() => setOpen(false), []);
    const ref = useClickOutside<HTMLDivElement>(close);
    useEscape(close);
    return (
        <div className="dropdown" ref={ref}>
            {trigger(() => setOpen((o) => !o), open)}
            {open && (
                <div className="menu" style={{ top: 'calc(100% + 6px)', [align]: 0, minWidth: width }} role="menu">
                    {Array.isArray(items)
                        ? (items as MenuEntry[]).map((item, i) => item.separator
                            ? <div key={i} className="menu-sep" />
                            : item.href
                                ? <a key={i} className={clsx('menu-item', item.danger && 'danger', item.active && 'active')} href={item.href} role="menuitem">{item.icon && <Icon name={item.icon} size={16} />}{item.label}</a>
                                : <button key={i} type="button" className={clsx('menu-item', item.danger && 'danger', item.active && 'active')} role="menuitem" onClick={() => { close(); item.onClick?.(); }}>{item.icon && <Icon name={item.icon} size={16} />}{item.label}</button>)
                        : items}
                </div>
            )}
        </div>
    );
}

/** The "⋮" actions button used in table rows. */
export function RowMenu({ items }: { items: MenuEntry[] }) {
    return (
        <span onClick={(e) => e.stopPropagation()}>
            <Dropdown items={items} trigger={(toggle) => (
                <button type="button" className="btn btn-ghost btn-icon btn-sm" onClick={toggle} aria-label={t('ui.actions')}><Icon name="more-vertical" size={18} /></button>
            )} />
        </span>
    );
}

/* ---------- Toasts ---------- */
interface ToastItem { id: number; kind: 'success' | 'error' | 'info'; text: string; detail?: string }
const ToastCtx = createContext<(kind: ToastItem['kind'], text: string, detail?: string) => void>(() => undefined);
let pushRef: ((kind: ToastItem['kind'], text: string, detail?: string) => void) | null = null;

export function ToastProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<ToastItem[]>([]);
    const push = useCallback((kind: ToastItem['kind'], text: string, detail?: string) => {
        const id = Date.now() + Math.random();
        setItems((list) => [...list, { id, kind, text, detail }]);
        setTimeout(() => setItems((list) => list.filter((i) => i.id !== id)), kind === 'error' ? 7000 : 4000);
    }, []);
    pushRef = push;
    return (
        <ToastCtx.Provider value={push}>
            {children}
            {createPortal(
                <div className="toasts" aria-live="polite">
                    {items.map((i) => (
                        <div key={i.id} className={clsx('toast', i.kind)}>
                            <Icon name={i.kind === 'success' ? 'check-circle' : i.kind === 'error' ? 'circle-alert' : 'info'} size={18} />
                            <div>{i.text}{i.detail && <small>{i.detail}</small>}</div>
                        </div>
                    ))}
                </div>,
                document.body,
            )}
        </ToastCtx.Provider>
    );
}

export const useToast = () => useContext(ToastCtx);

/** Toast from outside React components. */
export const toast = {
    success: (text: string) => pushRef?.('success', text),
    error: (text: string, ref?: string) => pushRef?.('error', text, ref ? `${t('errors.reference')}: ${ref}` : undefined),
    info: (text: string) => pushRef?.('info', text),
};
