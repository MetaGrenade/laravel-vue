import inertia from '@inertiajs/vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
            // Fonts are downloaded at build time and served from the app's own
            // origin, avoiding a render-blocking third-party stylesheet.
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js'),
            'ziggy-js': resolve(import.meta.dirname, 'vendor/tightenco/ziggy'),
        },
    },
    build: {
        rolldownOptions: {
            output: {
                // Keep the framework runtime (needed by every page) in its own
                // long-lived chunk so application deploys don't invalidate it.
                // Heavier libraries (editor, charts) are split automatically and
                // only load on the pages that use them.
                codeSplitting: {
                    groups: [{ name: 'vendor-vue', test: /node_modules[\\/](@vue|vue|@inertiajs)[\\/]/ }],
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/.claude/**'],
        },
    },
});
