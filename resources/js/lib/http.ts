const readCookie = (name: string): string | undefined => {
    if (typeof document === 'undefined') {
        return undefined;
    }

    const match = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${name}=`));

    if (!match) {
        return undefined;
    }

    const value = match.slice(name.length + 1);

    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
};

/**
 * CSRF headers for requests made outside Inertia (fetch, broadcasting auth).
 *
 * The XSRF-TOKEN cookie is refreshed on every response, whereas the
 * <meta name="csrf-token"> tag is only rendered on full page loads and goes
 * stale when the session is regenerated (e.g. after logging in). Laravel checks
 * X-CSRF-TOKEN before X-XSRF-TOKEN, so the meta tag is only used as a fallback.
 */
export function csrfHeaders(): Record<string, string> {
    const xsrfToken = readCookie('XSRF-TOKEN');

    if (xsrfToken) {
        return { 'X-XSRF-TOKEN': xsrfToken };
    }

    const metaToken = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') : null;

    return metaToken ? { 'X-CSRF-TOKEN': metaToken } : {};
}

/**
 * Default headers for same-origin JSON requests made with fetch().
 */
export function jsonHeaders(extra: Record<string, string> = {}): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeaders(),
        ...extra,
    };
}
