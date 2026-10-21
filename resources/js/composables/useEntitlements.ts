import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * What the signed-in user has been given by a purchase, for showing or hiding things. This is only for the screen: the
 * server decides what the user may do (the `entitled` route middleware, `$user->canUse()`).
 */
export function useEntitlements() {
    const page = usePage();
    const features = computed<string[]>(() => page.props.auth.entitlements?.features ?? []);
    // An empty set may arrive as an empty list rather than an empty object, and reading a key from either is fine.
    const limits = computed<Record<string, number>>(() => page.props.auth.entitlements?.limits ?? {});

    /** How much of a limit the user has (0 for one they do not hold). */
    function limit(key: string): number {
        return limits.value[key] ?? 0;
    }

    /** Whether the user holds it: a feature that is on, or a limit above nothing. */
    function can(key: string): boolean {
        return features.value.includes(key) || limit(key) > 0;
    }

    return { can, limit, features, limits };
}
