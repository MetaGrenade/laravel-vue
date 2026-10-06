/**
 * Stripe Elements appearance matching the app's current theme.
 *
 * Elements render inside an iframe, so they cannot read our CSS variables;
 * the values are resolved from the document when the element is created.
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
