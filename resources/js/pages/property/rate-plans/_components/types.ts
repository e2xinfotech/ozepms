import type { OccupancyRule } from '../../_accommodation/shared';

export interface RatePlanRow {
    id: string;
    name: string;
    code: string;
    description: string | null;
    meal_plan: { code: string; label: string } | null;
    room_types: string[];
    all_room_types: boolean;
    policy: { code: string; name: string; refundable: boolean } | null;
    base_rate: string | null;
    min_los: number;
    channels: { pms: boolean; booking_engine: boolean; channels: boolean };
    is_mapped: boolean;
    is_active: boolean;
    is_default: boolean;
}

export interface ProductInfo {
    id: string;
    room_type: string;
    room_type_name: string;
    room_type_code: string;
    rate_plan: string;
    pricing_mode: 'manual' | 'derived';
    default_price: string | null;
    base_price: string | null;
    parent_rate_plan: string | null;
    parent_rate_plan_name: string | null;
    adjust_type: string | null;
    adjust_value: string | null;
    is_default: boolean;
    is_active: boolean;
    occupancy_rules: OccupancyRule[];
}

export interface PolicyRule { applies_to: string; hours_before_arrival: number; charge_type: string; charge_value: string | null }

export interface RatePlanDetail extends RatePlanRow {
    payment_type: string;
    deposit_value: string | null;
    default_max_los: number | null;
    min_advance_days: number | null;
    max_advance_days: number | null;
    sell_on_pms: boolean;
    sell_on_booking_engine: boolean;
    sell_on_channels: boolean;
    mapped_products: number;
    cancellation_policy: { code: string; name: string; refundable: boolean; description: string | null; rules: PolicyRule[] } | null;
    products: ProductInfo[];
    history?: { action: string; label: string; user: string | null; at: string | null }[];
    created_at: string | null;
    updated_at: string | null;
}
