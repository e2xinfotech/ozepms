/** Shapes returned by CalendarQuery (app/Domain/Inventory/Queries/CalendarQuery.php). Short keys keep the payload small. */

export interface Day { date: string; day: number; dow: number; weekend: boolean; past?: boolean }

/** Availability of a room type for one night. */
export interface InvDay {
    t: number;            // total rooms
    s: number;            // sold
    h: number;            // held (booking engine, awaiting payment)
    o: number;            // out of order
    l: number | null;     // sell limit
    a: number;            // available to sell
    ss: boolean;          // room-type stop sell
    d: boolean;           // no stored row yet (computed default)
}

/** Price and restrictions of a product (room type × rate plan) for one night. */
export interface RateDay {
    p: string | null;                     // price for the base occupancy
    o: Record<string, string> | null;     // fixed prices per number of adults
    d: boolean;                           // default value (not stored for this date)
    i: boolean;                           // restrictions inherited from the parent product
    min: number;
    max: number | null;
    mla: number | null;                   // min stay on arrival
    cta: boolean;
    ctd: boolean;
    ss: boolean;                          // rate plan closed
    cut: number | null;                   // book at least N days ahead
    adv: number | null;                   // book at most N days ahead
}

export interface ProductRow {
    id: string;
    rate_plan: { id: string; code: string; name: string };
    meal_plan: string | null;
    pricing_mode: 'manual' | 'derived';
    parent: string | null;
    inherits_restrictions: boolean;
    is_active: boolean;
    base_adults: number;
    days: RateDay[];
}

export interface Bar {
    kind: 'reservation' | 'block';
    start: number;        // index of the first night in the window
    end: number;          // index of the last night (inclusive)
    status: string;       // confirmed | in_house | pending | checked_out | blocked | out_of_service
    check_in: string;
    check_out: string;
    cont_before: boolean;
    cont_after: boolean;
    guest?: string;
    reference?: string;
    adults?: number;
    children?: number;
    url?: string | null;
    block_type?: string;
    reason?: string | null;
}

export interface UnitRow {
    id: string;
    name: string;
    floor: string | null;
    is_active: boolean;
    housekeeping: string;
    status: string;
    bars: Bar[];
}

export interface RoomTypeRow {
    id: string;
    code: string;
    name: string;
    is_active: boolean;
    image: string | null;
    units_count: number;
    units_range: string | null;
    max_adults: number;
    inventory: InvDay[];
    products: ProductRow[];
    units: UnitRow[];
}

export interface Grid {
    from: string;
    to: string;
    today: string;
    currency: string;
    days: Day[];
    room_types: RoomTypeRow[];
}

export type View = 'inventory' | 'reservations';
export type Range = 'day' | 'week' | 'month' | 'year';

export interface Filters {
    view: View;
    range: Range;
    from: string;
    room_type: string | null;
    unit: string | null;
    rate_plan: string | null;
    status: string | null;          // '' active only · all · inactive
    availability: string | null;    // sold_out · low · available
    restriction: string | null;     // any · stop_sell · cta · ctd · min_los · max_los · cutoff
    price_min: string | null;
    price_max: string | null;
}

/** Year overview (CalendarYearQuery): per room type one level per night and the lowest open price. */
export interface YearMonth { month: string; days: number; first_dow: number; start: number }

export interface YearRow {
    id: string;
    code: string;
    name: string;
    is_active: boolean;
    levels: string;                  // per night: 0 sold out · 1 low · 2 available · s stop sell · . no data
    prices: (string | null)[];
    month_min: (string | null)[];
}

export interface YearData {
    from: string;
    to: string;
    today: string;
    currency: string;
    months: YearMonth[];
    room_types: YearRow[];
}

/** Cells chosen for editing: one row, a run of consecutive nights. */
export interface Selection {
    kind: 'room_type' | 'product';
    rowId: string;          // room type or product public id
    roomTypeId: string;
    start: number;
    end: number;
}

export interface Option { value: string; label: string }
