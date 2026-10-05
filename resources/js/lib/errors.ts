/** Sends browser errors to the server log (/web-api/client-errors). */
let sent = 0;

function report(payload: Record<string, unknown>): void {
    if (sent >= 10) return; // never flood the server from one page
    sent++;
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const body = JSON.stringify({ ...payload, url: window.location.pathname, page: document.body.dataset.page ?? '' });
    fetch('/web-api/client-errors', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
        body,
    }).catch(() => undefined);
}

export function installErrorReporting(): void {
    window.addEventListener('error', (event) => {
        report({ message: event.message, source: event.filename, line: event.lineno, column: event.colno, stack: event.error?.stack });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason as { message?: string; stack?: string } | undefined;
        if (reason && 'status' in (reason as object)) return; // API errors are handled by the page
        report({ message: reason?.message ?? String(event.reason), stack: reason?.stack });
    });
}

export function reportError(error: Error, info?: string): void {
    report({ message: error.message, stack: error.stack, component: info });
}
