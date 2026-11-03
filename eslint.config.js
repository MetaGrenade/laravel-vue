import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'tailwind.config.js', 'resources/js/components/ui/*'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    {
        // Text that a language file should hold. A warning, not an error, and only for what has been converted to keys so far
        // (docs/i18n.md): a file that is still in literal English is not reported until it is added here. AppHeader.vue and
        // UserMenuContent.vue are not in the list yet: their menus are still literal.
        files: [
            'resources/js/pages/auth/**/*.vue',
            'resources/js/layouts/auth/AuthSimpleLayout.vue',
            'resources/js/layouts/AuthLayout.vue',
            'resources/js/layouts/app/AppHeaderLayout.vue',
            'resources/js/components/AppFooter.vue',
            'resources/js/components/ThemeToggle.vue',
            'resources/js/components/CookieConsentBanner.vue',
            'resources/js/components/ImpersonationBanner.vue',
            'resources/js/components/RecoveryCodesPrompt.vue',
            'resources/js/components/dashboard/OnboardingCard.vue',
        ],
        rules: {
            'vue/no-bare-strings-in-template': 'warn',
        },
    },
    prettier,
);
