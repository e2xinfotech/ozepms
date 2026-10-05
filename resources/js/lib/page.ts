/** Data the server embeds in every page (see app/Support/Page.php). */
export interface MenuItem {
    key: string;
    label: string;
    icon: string;
    url: string | null;
    active: boolean;
    phase: number | null;
}

export interface SubscriptionState {
    status: string;
    plan: string | null;
    ends_on: string | null;
    days_left: number | null;
    read_only: boolean;
    warning: boolean;
}

export interface ShellData {
    brand: { name: string; tagline: string; company: string; support_email: string; auth_image?: string | null };
    locales: Record<string, string>;
    sso: { google: boolean; microsoft: boolean };
    menu?: MenuItem[];
    is_platform?: boolean;
    admin_url?: string | null;
    support_mode?: boolean;
    user?: { name: string; email: string; initials: string; avatar: string | null; role: string | null; two_factor: boolean };
    property?: {
        code: string; name: string; location: string; image: string | null;
        currency: string; timezone: string; subscription: SubscriptionState;
    } | null;
    properties?: { code: string; name: string; location: string }[];
    current_route?: string | null;
}

export interface PagePayload<P = Record<string, unknown>> {
    page: string;
    layout: 'app' | 'auth' | 'bare';
    props: P;
    shell: ShellData;
    i18n: Record<string, unknown>;
    locale: string;
    flash: { success?: string; notice?: string; error?: string };
}

let cached: PagePayload | null = null;

export function payload<P = Record<string, unknown>>(): PagePayload<P> {
    if (!cached) {
        const el = document.getElementById('oz-page');
        cached = JSON.parse(el?.textContent || '{}') as PagePayload;
    }
    return cached as PagePayload<P>;
}

/** Current property code (from the URL /p/{code}/...) or null outside a property. */
export function propertyCode(): string | null {
    return payload().shell.property?.code ?? null;
}

/** Builds a URL inside the current property: propertyUrl('/users') → /p/P1001/users */
export function propertyUrl(path: string): string {
    const code = propertyCode();
    return code ? `/p/${code}${path}` : path;
}

export function apiUrl(path: string): string {
    return `/web-api${path}`;
}

export function propertyApiUrl(path: string): string {
    return apiUrl(propertyUrl(path));
}
