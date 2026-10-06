import type Echo from 'laravel-echo';
import { jsonHeaders } from './http';

declare global {
    interface Window {
        Echo?: Echo<'pusher'>;
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

/**
 * Whether real-time broadcasting is configured for this build.
 */
export const isBroadcastingEnabled = (): boolean =>
    (import.meta.env.VITE_BROADCAST_DRIVER || 'pusher') === 'pusher' && Boolean(import.meta.env.VITE_PUSHER_APP_KEY);

const createEchoInstance = async (): Promise<Echo<'pusher'> | null> => {
    if (typeof window === 'undefined' || !isBroadcastingEnabled()) {
        return null;
    }

    // Loaded on demand so guests and pages without real-time features don't
    // download the WebSocket client.
    const [{ default: EchoClient }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);

    const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
    const host = import.meta.env.VITE_PUSHER_HOST || `ws-${cluster}.pusher.com`;
    const scheme = import.meta.env.VITE_PUSHER_SCHEME || 'https';
    const port = Number(import.meta.env.VITE_PUSHER_PORT || (scheme === 'https' ? 443 : 80));
    const forceTlsEnv = import.meta.env.VITE_PUSHER_FORCE_TLS;
    const forceTls = forceTlsEnv === undefined || forceTlsEnv === '' ? scheme === 'https' : booleanEnv(forceTlsEnv);

    return new EchoClient({
        broadcaster: 'pusher',
        Pusher,
        key: import.meta.env.VITE_PUSHER_APP_KEY as string,
        cluster,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: forceTls,
        disableStats: true,
        enabledTransports: ['ws', 'wss'],
        // headersProvider runs for every auth request, so the CSRF token is
        // always current (it changes when the session is regenerated).
        channelAuthorization: {
            endpoint: '/broadcasting/auth',
            transport: 'ajax',
            headersProvider: () => jsonHeaders(),
        },
    });
};

let echoPromise: Promise<Echo<'pusher'> | null> | null = null;

/**
 * Lazily create (once) and return the shared Echo instance, or null when
 * broadcasting is not configured.
 */
export const loadEcho = (): Promise<Echo<'pusher'> | null> => {
    if (typeof window === 'undefined') {
        return Promise.resolve(null);
    }

    echoPromise ??= createEchoInstance()
        .then((echo) => {
            window.Echo = echo ?? undefined;

            return echo;
        })
        .catch((error) => {
            console.warn('Unable to initialise real-time updates', error);

            return null;
        });

    return echoPromise;
};

/**
 * The Echo instance if it has already been loaded.
 */
export const currentEcho = (): Echo<'pusher'> | null => (typeof window === 'undefined' ? null : (window.Echo ?? null));

export const leaveEchoChannel = (channel: string): void => {
    currentEcho()?.leave(channel);
};
