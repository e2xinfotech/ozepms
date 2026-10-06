/**
 * Status → colour tone and translation key, in one place.
 * Tones map to the --{tone}-bg / --{tone}-fg tokens in tokens.css.
 */
export type Tone = 'green' | 'blue' | 'amber' | 'red' | 'orange' | 'violet' | 'purple' | 'sky' | 'teal' | 'pink' | 'rose' | 'slate';

const tones: Record<string, Tone> = {
    // generic
    active: 'green', inactive: 'slate', disabled: 'slate', invited: 'amber', locked: 'red',
    // property
    onboarding: 'amber', suspended: 'red', setup: 'amber',
    // subscription
    trial: 'blue', grace: 'amber', expired: 'red', cancelled: 'slate', none: 'red',
    // reservation
    inquiry: 'slate', hold: 'amber', pending: 'amber', confirmed: 'green', checked_in: 'blue', in_house: 'blue',
    checked_out: 'slate', no_show: 'red', upcoming: 'green', group: 'violet', today_checkout: 'red',
    // rooms / housekeeping
    available: 'green', occupied: 'red', out_of_service: 'orange', out_of_order: 'red', clean: 'green', dirty: 'amber', inspected: 'blue',
    // payments
    paid: 'green', partial: 'amber', unpaid: 'red', refunded: 'slate',
    // billing (folio lines, payment rows, invoices)
    captured: 'green', failed: 'red', partially_refunded: 'amber', void: 'slate', reversal: 'slate', posted: 'blue',
    open: 'blue', closed: 'slate', tax_invoice: 'blue', credit_note: 'violet', deposit: 'sky', invoiced: 'teal',
    // policies
    refundable: 'green', non_refundable: 'orange', flexible: 'green',
    // taxes & fees, room blocks
    tax: 'red', service_charge: 'green', fee: 'blue', maintenance: 'orange', owner_hold: 'violet',
    // errors
    warning: 'amber', error: 'red', critical: 'red', resolved: 'green', enabled: 'green',
};

export function toneOf(status: string | null | undefined): Tone {
    return (status && tones[status]) || 'slate';
}

/**
 * Calendar reservation / block bars (design legend: Confirmed, In-House, Pending, Blocked, Out of Service).
 * Out of service is grey on the calendar, unlike the orange room status badge.
 */
const barTones: Record<string, Tone> = {
    confirmed: 'green', in_house: 'blue', pending: 'amber', checked_out: 'slate', blocked: 'pink', out_of_service: 'slate',
};

export function barToneOf(status: string): Tone {
    return barTones[status] ?? toneOf(status);
}
