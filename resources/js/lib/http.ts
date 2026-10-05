/**
 * The only way pages talk to the server. Sends the CSRF token, parses the
 * standard error format { error: { code, message, fields, ref } } and throws ApiError.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public code: string,
        public fields: Record<string, string[]> = {},
        public ref?: string,
    ) {
        super(message);
    }

    /** First message for one field, for showing under an input. */
    field(name: string): string | undefined {
        return this.fields[name]?.[0];
    }
}

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

async function request<T>(method: Method, url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (res.status === 204) return undefined as T;

    let data: unknown = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }

    if (!res.ok) {
        const err = (data as { error?: { message?: string; code?: string; fields?: Record<string, string[]>; ref?: string } } | null)?.error;
        if (res.status === 401 && !url.includes('/auth/')) {
            window.location.href = '/login';
        }
        throw new ApiError(err?.message ?? res.statusText, res.status, err?.code ?? 'HTTP_' + res.status, err?.fields ?? {}, err?.ref);
    }
    return data as T;
}

export const http = {
    get: <T>(url: string, params?: Record<string, string | number | boolean | null | undefined>) => {
        const qs = params
            ? '?' + new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => [k, String(v)])).toString()
            : '';
        return request<T>('GET', url + (qs === '?' ? '' : qs));
    },
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body ?? {}),
    put: <T>(url: string, body?: unknown) => request<T>('PUT', url, body ?? {}),
    patch: <T>(url: string, body?: unknown) => request<T>('PATCH', url, body ?? {}),
    delete: <T>(url: string) => request<T>('DELETE', url),
};

/** Updates the query string and reloads the page (filters, tabs, pagination in a multi-page app). */
export function navigateWithQuery(changes: Record<string, string | number | null | undefined>, resetPage = true): void {
    const url = new URL(window.location.href);
    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === undefined || value === '') url.searchParams.delete(key);
        else url.searchParams.set(key, String(value));
    }
    if (resetPage && !('page' in changes)) url.searchParams.delete('page');
    window.location.href = url.toString();
}

export function query(name: string): string {
    return new URL(window.location.href).searchParams.get(name) ?? '';
}
