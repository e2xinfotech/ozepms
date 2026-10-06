export interface RoomRow {
    id: string;
    name: string;
    floor: string | null;
    building: string | null;
    room_type: { id: string; name: string; code: string };
    status: 'available' | 'occupied' | 'out_of_service' | 'out_of_order' | 'inactive';
    housekeeping_status: 'clean' | 'dirty' | 'inspected';
    last_cleaned_at: string | null;
    is_active: boolean;
    guest: { name: string; reservation: string; reservation_id: string } | null;
}

export interface RoomDetail extends RoomRow {
    notes: string | null;
    max_adults: number | null;
    max_occupancy: number | null;
    extra_bed_allowed: boolean;
    default_rate_plan: string | null;
    next_cleaning_at: string | null;
    in_maintenance: boolean;
    blocks: { id: number; type: string; start_date: string; end_date: string; reason: string | null }[];
    amenities: { code: string; label: string; icon: string | null; source: 'room_type' | 'room' }[];
    images: { id: number; url: string; alt: string | null }[];
    history: { action: string; label: string; user: string | null; at: string | null }[];
}
