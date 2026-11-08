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
        // A Checkbox or a Switch (Reka UI, wrapped in resources/js/components/ui) binds with `v-model`, or with `:model-value` and
        // `@update:model-value`. `checked` and `update:checked` are Radix Vue's, from before the move to Reka UI: Reka has neither, so
        // `v-model:checked` leaves the form field as it was, and `:checked` / `@update:checked` fall through to the page as a plain
        // attribute and a listener that never fires. Vue and the type checker accept all of them, which is how the Discord pages kept
        // them. A native `<input :checked>` is fine and is not reported.
        files: ['resources/js/**/*.vue'],
        rules: {
            'vue/no-restricted-syntax': [
                'error',
                {
                    selector: "VAttribute[directive=true][key.name.name='model'][key.argument.name='checked']",
                    message: 'Bind a Checkbox or a Switch with v-model, not v-model:checked: Reka UI has no `checked` prop (docs/upgrading.md).',
                },
                {
                    selector: "VAttribute[directive=true][key.name.name='on'][key.argument.name='update:checked']",
                    message:
                        'A Checkbox or a Switch emits update:model-value, not update:checked: Reka UI never emits the latter (docs/upgrading.md).',
                },
                {
                    selector:
                        "VElement[name=/^(switch|checkbox)$/] > VStartTag > VAttribute[directive=true][key.name.name='bind'][key.argument.name='checked']",
                    message: 'A Checkbox or a Switch takes :model-value, not :checked: Reka UI has no `checked` prop (docs/upgrading.md).',
                },
            ],
        },
    },
    {
        // Text that a language file should hold. A warning, not an error, and only for what has been converted to keys so far
        // (docs/i18n.md): a file that is still in literal English is not reported until it is added here. Add a file when it is
        // converted.
        files: [
            'resources/js/pages/auth/**/*.vue',
            'resources/js/layouts/auth/AuthSimpleLayout.vue',
            'resources/js/layouts/AuthLayout.vue',
            'resources/js/layouts/app/AppHeaderLayout.vue',
            'resources/js/components/AppHeader.vue',
            'resources/js/components/UserMenuContent.vue',
            'resources/js/layouts/settings/SettingsLayout.vue',
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
