import { lazy, Suspense, useMemo, type ComponentType } from 'react';
import { EmptyState } from '@/components/ui';
import { t } from '@/lib/i18n';
import type { BillingReservation } from './types';

/**
 * Embeds a component of the billing module (./billing/<Name>.tsx, default export) without a hard
 * dependency: while billing has not shipped a component, an information placeholder is shown.
 * Contract: props { reservation: BillingReservation, onChanged?, open?, onClose?, onSaved? }.
 */
const modules = import.meta.glob<{ default: ComponentType<BillingProps> }>('./billing/*.tsx');

export interface BillingProps {
    reservation: BillingReservation;
    onChanged?: () => void;
    open?: boolean;
    onClose?: () => void;
    onSaved?: () => void;
    compact?: boolean;
}

export function hasBilling(name: string): boolean {
    return `./billing/${name}.tsx` in modules;
}

export function BillingSlot({ name, fallback, ...props }: BillingProps & { name: string; fallback?: React.ReactNode }) {
    const Comp = useMemo(() => {
        const loader = modules[`./billing/${name}.tsx`];
        return loader ? lazy(loader) : null;
    }, [name]);
    if (!Comp) {
        return fallback === undefined ? <EmptyState icon="receipt" title={t('reservations.detail.billing_pending')} /> : <>{fallback}</>;
    }
    return (
        <Suspense fallback={<div className="skeleton" style={{ height: 120 }} />}>
            <Comp {...props} />
        </Suspense>
    );
}
