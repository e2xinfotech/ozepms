import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from './http';

/** Runs an async action with loading + error state. */
export function useAction<A extends unknown[], R>(fn: (...args: A) => Promise<R>) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);
    const run = useCallback(async (...args: A): Promise<R | undefined> => {
        setBusy(true);
        setError(null);
        try {
            return await fn(...args);
        } catch (e) {
            if (e instanceof ApiError) setError(e);
            else setError(new ApiError((e as Error).message, 0, 'CLIENT_ERROR'));
            return undefined;
        } finally {
            setBusy(false);
        }
    }, [fn]);
    return { run, busy, error, setError };
}

/** Simple form state helper. */
export function useForm<T extends Record<string, unknown>>(initial: T) {
    const [data, setData] = useState<T>(initial);
    const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => setData((d) => ({ ...d, [key]: value })), []);
    return { data, set, setData };
}

export function useClickOutside<T extends HTMLElement>(onOutside: () => void) {
    const ref = useRef<T>(null);
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onOutside();
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [onOutside]);
    return ref;
}

export function useDebounced<T>(value: T, ms = 250): T {
    const [v, setV] = useState(value);
    useEffect(() => {
        const id = setTimeout(() => setV(value), ms);
        return () => clearTimeout(id);
    }, [value, ms]);
    return v;
}

/**
 * A query-string value kept in sync with the URL without reloading the page
 * (e.g. ?selected=<id> for the open side panel), so Back/Refresh/bookmarks work.
 */
export function useQueryState(name: string, initial = ''): [string, (value: string | null) => void] {
    const [value, setValue] = useState(initial);
    const update = useCallback((next: string | null) => {
        const url = new URL(window.location.href);
        if (next) url.searchParams.set(name, next); else url.searchParams.delete(name);
        window.history.replaceState(null, '', url.toString());
        setValue(next ?? '');
    }, [name]);
    return [value, update];
}
