import type { SharedData } from '@/types';
import '@inertiajs/core';

// Register the props shared by HandleInertiaRequests so `usePage().props`
// is fully typed on every page.
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedData;
    }
}
