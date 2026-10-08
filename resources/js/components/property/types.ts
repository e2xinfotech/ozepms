import type { Option } from '@/components/ui';
import type { SubscriptionState } from '@/lib/page';

/** Option lists for the property form (App\Support\Lookups::propertyForm). */
export interface PropertyLookups {
    countries: (Option & { phone: string | null; currency: string | null; timezone: string | null })[];
    currencies: Option[];
    timezones: Option[];
    types: (Option & { code: string })[];
    languages: Option[];
    date_formats: string[];
    number_formats: string[];
    defaults: { country: string; currency: string; timezone: string; check_in: string; check_out: string };
}

/** One row of a property list (PropertyListQuery::row). */
export interface PropertyRow {
    code: string;
    name: string;
    tagline: string | null;
    city: string | null;
    country: string | null;
    country_code: string | null;
    location: string;
    type: string | null;
    type_label: string | null;
    rooms: number;
    status: string;
    star_rating: number | null;
    image: string | null;
    plan: string | null;
    subscription_status: string;
}

export interface PropertyKpis { total: number; active: number; inactive: number; setup: number; countries: number; rooms: number }

/** Full property details (PropertyResource). */
export interface PropertyDetail {
    code: string;
    name: string;
    tagline: string | null;
    legal_name: string | null;
    property_type_id: number | null;
    type: string | null;
    type_label: string | null;
    star_rating: number | null;
    status: string;
    phone: string | null;
    email: string | null;
    website: string | null;
    description: string | null;
    contact_person: string | null;
    image: string | null;
    logo: string | null;
    facilities: Array<{ code: string; name: string; icon: string | null }>;
    country_iso2: string | null;
    country: string | null;
    state_id: number | null;
    state: string | null;
    city: string | null;
    postcode: string | null;
    address_line1: string | null;
    address_line2: string | null;
    address: string;
    location: string;
    latitude: string | null;
    longitude: string | null;
    maps_url: string | null;
    currency_code: string;
    timezone: string;
    timezone_label: string | null;
    default_language: string;
    languages: string[];
    date_format: string | null;
    number_format: string | null;
    week_start: number;
    check_in_time: string;
    check_out_time: string;
    tax_registration_no: string | null;
    business_date: string | null;
    rooms: number;
    rate_plans: number;
    users: number;
    owner: { id: string; name: string; email: string; phone: string | null; can_impersonate?: boolean } | null;
    subscription: SubscriptionState;
    plan_id: number | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface PlanOption { value: number; label: string; price: string; currency: string; cycle: string; trial_days: number }
