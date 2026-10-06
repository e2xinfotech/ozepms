export type OfferStatus = 'active' | 'scheduled' | 'expired' | 'inactive';

export interface OfferRow {
    id: string; code: string; name: string; offer_type: string; type_label: string; promo_code: string | null;
    discount_type: string; discount_value: string; discount_label: string; validity_label: string;
    stay_from: string | null; stay_to: string | null; status: OfferStatus; is_active: boolean; channels_label: string;
    priority: number; redemptions: number; max_redemptions: number | null;
}

export interface OfferDetail extends OfferRow {
    description: string | null; image_url: string | null; highlights: string[]; booking_window: string; weekdays_label: string;
    room_types: string[]; rate_plans: string[]; conditions: string[];
    channels: { pms: boolean; booking_engine: boolean; sources: string[] };
    is_stackable: boolean;
    stats: { bookings: number; discount: string; revenue: string };
    history: { action: string; label: string; user: string | null; at: string | null }[];
}

export interface OfferForm {
    id: string; name: string; code: string; offer_type: string; description: string | null; promo_code: string | null;
    discount_type: string; discount_value: string; min_nights: number | null; max_nights: number | null; min_amount: string | null;
    booking_from: string | null; booking_to: string | null; stay_from: string | null; stay_to: string | null; weekdays: number;
    min_advance_days: number | null; max_advance_days: number | null; priority: number; is_stackable: boolean;
    max_redemptions: number | null; redemptions: number; on_pms: boolean; on_booking_engine: boolean; is_active: boolean;
    room_types: string[]; rate_plans: string[]; sources: string[]; countries: string[]; country_mode: 'in' | 'not_in';
    min_adults: number | null; min_rooms: number | null; image_url: string | null;
}

export const OFFER_TYPES = ['room_discount', 'package', 'early_bird', 'last_minute', 'long_stay', 'other'];
export const DISCOUNT_TYPES = ['percent', 'fixed_per_night', 'fixed_per_stay', 'free_nights'];
export const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
