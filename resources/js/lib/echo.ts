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
    const forceTls = booleanEnv(import.meta.env.VITE_PUSHER_FORCE_TLS) || scheme === 'https';
    const csrfToken = getCsrfToken();

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
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
        },
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
