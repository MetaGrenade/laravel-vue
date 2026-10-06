import type { SharedData } from '@/types';
import type { Page, router } from '@inertiajs/core';

// Globals Inertia registers on every component instance, for use in templates.
declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof router;
        $page: Page<SharedData>;
    }
}
