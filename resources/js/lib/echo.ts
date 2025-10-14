import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Echo?: Echo;
        Pusher?: typeof Pusher;
    }
}

const booleanEnv = (value: string | boolean | undefined): boolean => {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'string') {
        return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
    }

    return false;
};

const getCsrfToken = (): string | undefined => {
    if (typeof document === 'undefined') {
        return undefined;
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? undefined;
};

const getCookieValue = (name: string): string | undefined => {
    if (typeof document === 'undefined') {
        return undefined;
    }

    return document.cookie
        .split('; ')
        .map((cookie) => cookie.split('='))
        .find(([key]) => key === name)?.[1];
};

const getXsrfToken = (): string | undefined => {
    const token = getCookieValue('XSRF-TOKEN');

    if (!token) {
        return undefined;
    }

    try {
        return decodeURIComponent(token);
    } catch (error) {
        console.warn('Unable to decode XSRF token cookie', error);
        return token;
    }
};

const createEchoInstance = (): Echo | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const broadcaster = import.meta.env.VITE_BROADCAST_DRIVER ?? 'pusher';

    if (String(broadcaster) !== 'pusher') {
        return null;
    }

    const key = import.meta.env.VITE_PUSHER_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    window.Pusher = Pusher;

    const cluster = (import.meta.env.VITE_PUSHER_APP_CLUSTER as string | undefined) ?? 'mt1';
    const host = (import.meta.env.VITE_PUSHER_HOST as string | undefined) ?? `ws-${cluster}.pusher.com`;
    const scheme = (import.meta.env.VITE_PUSHER_SCHEME as string | undefined) ?? 'https';
    const portValue = import.meta.env.VITE_PUSHER_PORT as string | undefined;
    const port = Number(portValue ?? (scheme === 'https' ? 443 : 80));
    const forceTlsEnv = import.meta.env.VITE_PUSHER_FORCE_TLS;
    const forceTls = forceTlsEnv === undefined
        ? scheme === 'https'
        : booleanEnv(forceTlsEnv);
    const csrfToken = getCsrfToken();
    const xsrfToken = getXsrfToken();

    return new Echo({
        broadcaster: 'pusher',
        key,
        cluster,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: forceTls,
        encrypted: forceTls,
        disableStats: true,
        enabledTransports: ['ws', 'wss'],
        withCredentials: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
            },
        },
        authorizer: (channel, options) => ({
            authorize(socketId: string, callback: (error: Error | null, data?: unknown) => void) {
                fetch(options.authEndpoint ?? '/broadcasting/auth', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        ...(options.auth?.headers ?? {}),
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channel.name,
                    }),
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            callback(new Error(`Broadcast auth failed with status ${response.status}`));
                            return;
                        }

                        const data = await response.json();
                        callback(null, data);
                    })
                    .catch((error) => {
                        callback(error instanceof Error ? error : new Error('Broadcast auth failed'));
                    });
            },
        }),
    });
};

export const getEcho = (): Echo | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    if (!window.Echo) {
        window.Echo = createEchoInstance() ?? undefined;
    }

    return window.Echo ?? null;
};

export const leaveEchoChannel = (channel: string): void => {
    const echo = getEcho();

    if (!echo) {
        return;
    }

    echo.leave(channel);
};
