import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
import { initializeFlashToast, type FlashMessages } from './lib/flashToast';
import { initializeZiggyRouteSync } from './lib/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

let initialFlash: FlashMessages | undefined;

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    pages: './pages',
    serverHead: 'seoHead',
    withApp: (app, { page }) => {
        initialFlash = page.props.flash as FlashMessages | undefined;

        app.use(ZiggyVue);
        initializeZiggyRouteSync(page.props.ziggy?.group);
    },
    progress: {
        color: '#4B5563',
        delay: 150,
    },
}).then(() => initializeFlashToast(initialFlash));

// This will set light / dark mode on page load...
initializeTheme();
