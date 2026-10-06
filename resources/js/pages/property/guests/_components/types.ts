/** JSON shapes of app/Domain/Guests/Queries/GuestQuery.php. */
export interface GuestRow {
    id: string; number: string; name: string; email: string | null; phone: string | null; nationality: string | null; guest_type: string;
    id_type: string | null; id_number: string | null; vip: boolean; tags: string[]; stays: number; last_stay: string | null; status: string; created_at: string | null;
}

export interface GuestStay { id: string; ref: string; check_in: string; check_out: string; nights: number; status: string; total: string; currency: string; unit: string | null; room_type: string | null; rate_plan: string | null }

export interface GuestDetail extends GuestRow {
    title: string | null; first_name: string; last_name: string | null; country: string | null; date_of_birth: string | null;
    address_line1: string | null; address_line2: string | null; city: string | null; postcode: string | null;
    company_name: string | null; company_tax_no: string | null; id_issuing: string | null; id_expiry: string | null;
    marketing_consent: boolean; notes_text: string | null; preferences: string | null;
    stay_history: GuestStay[];
    notes: { id: number; body: string; user: string | null; at: string | null }[];
    documents: { id: string; type: string; name: string; size: number; mime: string; at: string | null }[];
}
