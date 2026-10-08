export interface ConnectionRow {
    id: string; provider: string; name: string | null; hotel_id: string; status: string; rooms: number; rates: number;
    last_success_at: string | null; last_error: string | null; last_error_at: string | null; failures: number; next_attempt_at: string | null; waiting: boolean;
    approval: 'pending' | 'approved' | 'rejected' | 'suspended'; approval_note: string | null; requires_approval: boolean;
}
export interface CredentialField { key: string; type: 'secret' | 'text'; required?: boolean; has_value?: boolean; value?: string }
export interface ConnectionDetail extends ConnectionRow {
    is_staff: boolean; can_edit_credentials: boolean; webhook_url: string; webhook_secret: string | null; last_full_sync_at: string | null; sync_days: number; credential_fields: CredentialField[];
}
export interface MappingRate { product_id: number; name: string; code: string; sellable: boolean; external_rate_id: string; markup_type: 'none' | 'percent' | 'fixed'; markup_value: string }
export interface MappingRoom { room_type_id: string; code: string; name: string; external_room_id: string; rates: MappingRate[] }
export interface Listings { rooms: { id: string; name: string }[]; rates: { id: string; name: string; room_id: string }[] }

export const APPROVAL_TONE: Record<string, 'green' | 'amber' | 'red' | 'slate' | 'blue'> = { approved: 'green', pending: 'amber', rejected: 'red', suspended: 'red' };

export const STATUS_TONE: Record<string, 'green' | 'amber' | 'red' | 'slate' | 'blue'> = { active: 'green', pending: 'blue', paused: 'amber', error: 'red', disconnected: 'slate' };
