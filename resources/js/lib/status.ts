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
    // policies
    refundable: 'green', non_refundable: 'orange', flexible: 'green',
    // errors
    warning: 'amber', error: 'red', critical: 'red', resolved: 'green',
};

export function toneOf(status: string | null | undefined): Tone {
    return (status && tones[status]) || 'slate';
}
