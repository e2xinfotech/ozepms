/** JSON shapes of app/Domain/Reservations/Queries/ReservationPresenter.php. */
export interface UnitRef { id: string; name: string; housekeeping: string; from: string; to: string }

export interface ReservationRow {
    id: string;
    ref: string;
    status: string;
    payment_status: string;
    guest: { id: string | null; name: string; email: string | null; phone: string | null; nationality: string | null; vip: boolean };
    units: string[];
    room_type: { name: string; code: string }[];
    rate_plan: { name: string; code: string } | null;
    rooms: { id: string; status: string; room_type: string | null; room_type_id: string | null; check_in: string; check_out: string; unit: UnitRef | null }[];
    check_in: string;
    check_out: string;
    arrival_time: string | null;
    departure_time: string | null;
    nights: number;
    room_count: number;
    adults: number;
    children: number;
    infants: number;
    total: string;
    balance: string;
    currency: string;
    source: { code: string; name: string } | null;
    created_at: string | null;
    late_arrival: boolean;
    late_departure: boolean;
}

export interface PolicyRule { applies_to: string; hours_before_arrival: number; charge_type: string; charge_value: string | null }

export interface ReservationRoomDetail {
    id: string;
    status: string;
    room_type: { id: string; code: string; name: string; image: string | null } | null;
    rate_plan: { id: string | null; code: string | null; name: string | null };
    product_id: string | null;
    meal_plan: string | null;
    policy: { name: string | null; refundable: boolean; rules: PolicyRule[] };
    manual_rate: string | null;
    check_in: string;
    check_out: string;
    nights: number;
    adults: number;
    children: number;
    infants: number;
    child_ages: number[];
    unit: UnitRef | null;
    units: UnitRef[];
    room_total: string;
    tax_total: string;
    grand_total: string;
    rate: string | null;
    nightly: { date: string; price: string; net: string; tax: string; active: boolean }[];
    checked_in_at: string | null;
    checked_out_at: string | null;
}

export interface GuestProfile {
    id: string; number: string; title: string | null; guest_type: string; first_name: string; last_name: string | null;
    email: string | null; phone: string | null; nationality: string | null; country: string | null; date_of_birth: string | null;
    address: string[]; company_name: string | null; company_tax_no: string | null; id_type: string | null; id_number: string | null;
    id_issuing: string | null; id_expiry: string | null; vip: boolean; tags: string[]; notes: string | null; preferences: string | null;
}

export interface ReservationActions {
    edit: boolean; cancel: boolean; confirm: boolean; no_show: boolean; check_in: boolean; check_out: boolean; override_balance: boolean;
    assign: boolean; note: boolean; billing: boolean; payments: boolean; charges: boolean;
}

export interface ReservationDetail extends Omit<ReservationRow, 'rooms'> {
    rooms: ReservationRoomDetail[];
    purpose: string | null; market: string | null; travel_agent: string | null; company_name: string | null; channel_ref: string | null;
    special_requests: string | null; internal_notes: string | null;
    totals: { room_total: string; extras_total: string; discount_total: string; subtotal: string; tax_total: string; tax_rate: string; grand_total: string; paid: string; balance: string; billing_ready: boolean };
    cancellation: { fee: string | null; reason: string | null; at: string | null; fee_now: string | null };
    guest_profile: GuestProfile | null;
    companions: { id: string; name: string; nationality: string | null }[];
    created_by: string | null;
    updated_by: string | null;
    updated_at: string | null;
    confirmed_at: string | null;
    actions: ReservationActions;
}

export interface HistoryData {
    events: ({ type: 'status'; at: string; user: string | null; from: string | null; to: string; room: number | null; note: string | null }
        | { type: 'change'; at: string; user: string | null; action: string; keys: string[]; after: Record<string, unknown> | null })[];
    notes: { id: number; body: string; user: string | null; at: string | null }[];
    documents: { id: string; type: string; name: string; size: number; mime: string; at: string | null }[];
}

/** What the billing components receive (contract with the billing module, see .handoff). */
export interface BillingReservation { id: string; ref: string; status: string; currency: string; grand_total: string; guest_name: string }

export function billingRef(r: ReservationRow | ReservationDetail): BillingReservation {
    return { id: r.id, ref: r.ref, status: r.status, currency: r.currency, grand_total: r.total, guest_name: r.guest.name };
}
