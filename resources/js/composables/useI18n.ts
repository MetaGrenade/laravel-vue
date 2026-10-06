import { choose, interpolate, type Replacements } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const warned = new Set<string>();

/**
 * Translations for the active language.
 *
 * The strings come from lang/<locale>/<group>.php, for the groups listed in
 * config/i18n.php, and arrive as a shared Inertia prop. Because they are part
 * of the page props they are available during server-side rendering too.
 *
 *     const { t, tc } = useI18n();
 *     t('ui.nav.home');                                 // "Home"
 *     t('ui.footer.rights', { year: 2026, name: 'Acme' });
 *     tc('shop.items', 3);                              // "one item|:count items"
 */
export function useI18n() {
    const page = usePage<SharedData>();

    const locale = computed(() => page.props.locale ?? 'en');
    const messages = computed<Record<string, string>>(() => page.props.translations ?? {});

    function lookup(key: string): string | null {
        const message = messages.value[key];

        if (message !== undefined) {
            return message;
        }

        if (import.meta.env.DEV && !warned.has(key)) {
            warned.add(key);
            console.warn(`[i18n] Missing translation key "${key}" for locale "${locale.value}".`);
        }

        return null;
    }

    /** Translate a key, falling back to the key itself when it is missing. */
    function t(key: string, replacements: Replacements = {}): string {
        const message = lookup(key);

        return message === null ? key : interpolate(message, replacements);
    }

    /** Translate a pluralised key; `:count` is always available as a replacement. */
    function tc(key: string, count: number, replacements: Replacements = {}): string {
        const message = lookup(key);

        return message === null ? key : interpolate(choose(message, count, locale.value), { count, ...replacements });
    }

    /** Whether a key exists in the loaded translations. */
    function has(key: string): boolean {
        return key in messages.value;
    }

    return { t, tc, has, locale };
}
