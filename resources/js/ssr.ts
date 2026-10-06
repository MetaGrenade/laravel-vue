import { createInertiaApp } from '@inertiajs/vue3';
import { route as ziggyRoute, ZiggyVue, type Config, type RouteParams } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    pages: './pages',
    serverHead: 'seoHead',
    withApp: (app, { page }) => {
        // The browser receives Ziggy's route map via the @routes Blade directive.
        // During SSR it is passed through the initial page props instead.
        const ziggy = page.props.ziggy as Config & { location: string };
        const config = { ...ziggy, location: new URL(ziggy.location) };

        const route = (name: string, params?: RouteParams<string>, absolute?: boolean) => ziggyRoute(name, params, absolute, config);

        (globalThis as unknown as { route: typeof route }).route = route;

        app.use(ZiggyVue, config);
    },
});
