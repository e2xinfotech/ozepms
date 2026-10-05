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
    for (const [name, value] of Object.entries(replace)) {
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
