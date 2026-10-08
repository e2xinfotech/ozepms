import { toast } from '@/components/ui';
import { money } from '@/lib/format';
import { ApiError, http } from '@/lib/http';
import { propertyApiUrl } from '@/lib/page';

/** Types, API helpers and small utilities shared by the billing components (folio, payments, invoices). */

export interface Summary {
    total: string; paid: string; balance: string; currency: string; lines_count: number;
    posted: string; pending_room_charges: string; folio_no: string | null; status: string;
}

export interface FolioLineRow {
    id: string; date: string; type: string; department?: string | null; reference?: string | null; description: string; sac: string | null; quantity: string; unit_price: string;
    amount: string; tax: string; total: string; taxes: { component: string; label?: string; rate: string; amount: string }[];
    void: boolean; reversal: boolean; void_reason: string | null; invoice: string | null; posted_by: string | null; posted_at: string | null; can_void: boolean;
}

export interface FolioData {
    folio: { no: string; status: string; currency: string } | null;
    summary: Summary;
    lines: FolioLineRow[];
    pending: { date: string; description: string; amount: string; tax: string; total: string }[];
    taxes: { label: string; amount: string }[];
    invoiceable: boolean;
    can: { post?: boolean; override?: boolean; invoice?: boolean; payments?: boolean };
}

export interface PaymentRow {
    id: string; kind: 'payment' | 'refund'; method: string; gateway: string | null; amount: string; refunded: string; refundable: string;
    status: string; deposit: boolean; reference: string | null; notes: string | null; failure: string | null; parent: string | null;
    date: string | null; received_by: string | null;
}

export interface PaymentsData { rows: PaymentRow[]; summary: Summary; can: { manage: boolean; online: boolean } }

export interface InvoiceRow {
    id: string; number: string; type: 'tax_invoice' | 'credit_note'; date: string; bill_to: string; tax_no: string | null;
    taxable: string; tax: string; total: string; currency: string; cancelled: boolean; original: string | null; url: string;
}

export interface InvoicesData { rows: InvoiceRow[]; billable: boolean; pending_room_charges: string; can: { manage: boolean } }

export interface BillingOptions {
    services: { id: string; code: string; name: string; price: string; posting_rule: string; tax_category: string | null; department: string; quantity: string }[];
    departments: string[];
    tax_categories: { value: string; label: string; sac: string | null }[];
    methods: string[];
    online: boolean;
    stay: { nights: number; persons: number };
}

export const billingApi = (reservationId: string, path = '') => propertyApiUrl(`/reservations/${reservationId}${path}`);

/** Lists for the dialogs, fetched once per page and reservation. */
const optionCache = new Map<string, Promise<BillingOptions>>();
export function loadOptions(reservationId: string): Promise<BillingOptions> {
    let p = optionCache.get(reservationId);
    if (!p) {
        p = http.get<BillingOptions>(propertyApiUrl('/billing/options'), { reservation: reservationId });
        p.catch(() => optionCache.delete(reservationId));
        optionCache.set(reservationId, p);
    }
    return p;
}

/** A key per dialog opening: a double click or a retry after a timeout records the action once. */
export function newKey(): string {
    const c = globalThis.crypto as Crypto | undefined;
    if (c && typeof c.randomUUID === 'function') return c.randomUUID();
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

/** Shows the server message and returns the result; 422 errors go to onFieldError for inline display. */
export async function submit<T extends { message?: string }>(call: () => Promise<T>, onFieldError: (e: ApiError) => void): Promise<T | undefined> {
    try {
        const res = await call();
        if (res?.message) toast.success(res.message);
        return res;
    } catch (e) {
        const err = e instanceof ApiError ? e : new ApiError((e as Error).message, 0, 'CLIENT_ERROR');
        if (err.status === 422 && Object.keys(err.fields).length > 0) {
            onFieldError(err);
            // Focus the first invalid field.
            requestAnimationFrame(() => document.querySelector<HTMLElement>('.modal [aria-invalid="true"]')?.focus());
        } else {
            toast.error(err.message, err.ref);
        }
        return undefined;
    }
}

export function fmt(amount: string | number | null | undefined, currency: string): string {
    return money(amount, currency);
}

export function isPositive(v: string | number | null | undefined): boolean {
    return Number(v ?? 0) > 0.0049;
}
