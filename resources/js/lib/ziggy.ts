import { http, router } from '@inertiajs/vue3';
import type { Config } from 'ziggy-js';

/**
 * The route map rendered by the @routes Blade directive. It is a global
 * `const` (not a window property), and Ziggy's route() reads it on every call,
 * so updating its `routes` takes effect immediately.
 */
declare const Ziggy: Config | undefined;

type ZiggyProps = Partial<Config> & { location: string; group?: string };

const HEADER = 'X-Ziggy-Group';

const currentConfig = (): Config | undefined => (typeof Ziggy !== 'undefined' ? Ziggy : (globalThis as { Ziggy?: Config }).Ziggy);

/**
 * Keep the browser's Ziggy route map in step with the user's route group.
 *
 * Guests and members only receive public routes; staff also receive the
 * admin control panel routes (config/ziggy.php). @routes only runs on full
 * page loads, so when the group changes during an Inertia visit (signing in
 * as staff from the guest login page, or signing out) the server sends the
 * new map in the `ziggy` prop and it is swapped in here.
 */
export function initializeZiggyRouteSync(initialGroup: string | undefined): void {
    let group = initialGroup;

    http.onRequest((config) => {
        if (group) {
            config.headers = { ...config.headers, [HEADER]: group };
        }

        return config;
    });

    router.on('success', (event) => {
        const ziggy = event.detail.page.props.ziggy as ZiggyProps | undefined;
        const config = currentConfig();

        if (!ziggy?.routes || !config) {
            return;
        }

        config.routes = ziggy.routes;
        group = ziggy.group;
    });
}
