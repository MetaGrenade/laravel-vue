import { useAppearance } from '@/composables/useAppearance';
import { watch, type Ref } from 'vue';

/**
 * Stripe Elements appearance matching the app's current theme.
 *
 * Elements render inside an iframe, so they cannot read our CSS variables;
 * the values are resolved from the document each time this is called.
 */
export function stripeAppearance() {
    const isDark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    const styles = typeof document !== 'undefined' ? getComputedStyle(document.documentElement) : null;
    const token = (name: string, fallback: string) => {
        const value = styles?.getPropertyValue(name).trim();

        // Tokens are raw HSL channels ("243 75% 59%"); Stripe wants a full colour.
        return value ? `hsl(${value.split(/\s+/).join(', ')})` : fallback;
    };

    return {
        theme: isDark ? 'night' : 'stripe',
        variables: {
            colorPrimary: token('--primary', isDark ? '#818cf8' : '#4f46e5'),
            colorBackground: token('--card', isDark ? '#131316' : '#ffffff'),
            colorText: token('--foreground', isDark ? '#f5f5f5' : '#09090b'),
            colorDanger: token('--destructive', '#dc2626'),
            fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
            borderRadius: '8px',
        },
    } as const;
}

/**
 * Keep a mounted Elements instance in step with the theme: re-applies the
 * appearance whenever the user switches theme or the system preference changes.
 */
export function useStripeAppearanceSync(elements: Ref<{ update?: (options: Record<string, unknown>) => void } | null>) {
    const { resolvedAppearance } = useAppearance();

    // The <html> class is updated synchronously when the theme changes, so by
    // the time this (pre-flush) watcher runs the document already reflects it.
    watch(resolvedAppearance, () => {
        elements.value?.update?.({ appearance: stripeAppearance() });
    });
}
