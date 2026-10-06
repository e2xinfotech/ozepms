import { payload } from './page';

/**
 * Translations come from lang/{locale}/*.php on the server.
 *   t('users.add_user')                 → "Add User"
 *   t('ui.showing', { from: 1, to: 10 }) → replaces :from and :to
 */
export function t(key: string, replace: Record<string, string | number> = {}): string {
    const parts = key.split('.');
    let node: unknown = payload().i18n;
    for (const part of parts) {
        if (node && typeof node === 'object' && part in (node as Record<string, unknown>)) {
            node = (node as Record<string, unknown>)[part];
        } else {
            node = undefined;
            break;
        }
    }
    let text = typeof node === 'string' ? node : key;
    // Longest names first, so ":to" does not replace the start of ":total".
    for (const [name, value] of Object.entries(replace).sort((a, b) => b[0].length - a[0].length)) {
        text = text.replaceAll(`:${name}`, String(value));
    }
    return text;
}

/** Returns the translation or the fallback when the key is missing. */
export function tOr(key: string, fallback: string): string {
    const value = t(key);
    return value === key ? fallback : value;
}

export function locale(): string {
    return payload().locale || 'en';
}

/** Plural form: "one|many" texts pick the first part for a count of 1, the second otherwise. */
export function tc(key: string, count: number, replace: Record<string, string | number> = {}): string {
    const text = t(key, { count, ...replace });
    const [one, many] = text.split('|');
    return many === undefined ? one : count === 1 ? one : many;
}
