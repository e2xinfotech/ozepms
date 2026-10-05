import type { ActivityItem, PermissionGroup } from '@/components/users/UserBits';

export interface RoleInfo {
    code: string; name: string; description: string | null; color: string; is_system: boolean; assignable: boolean;
    users: number | null; permissions: string[];
}

export interface UserRow {
    id: string; name: string; email: string; job_title: string | null; role: string; role_name: string; role_color: string;
    is_owner: boolean; status: string; invited: boolean; last_login_at: string | null;
}

export interface UserDetail {
    id: string; name: string; email: string; phone_e164: string | null; job_title: string | null; locale: string; avatar: string | null;
    role: string | null; role_name: string | null; role_color: string | null; role_description: string | null; is_owner: boolean;
    status: string; account_status: string; two_factor: boolean; last_login_at: string | null; created_at: string | null; joined_at: string | null;
    permissions: string[]; properties: { code: string; name: string; city: string | null; role_name: string; status: string }[];
    activity: ActivityItem[];
}

export type { PermissionGroup };
