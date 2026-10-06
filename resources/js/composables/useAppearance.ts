import { computed, readonly, ref } from 'vue';

export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

const STORAGE_KEY = 'appearance';

/*
 * Shared, module-level state so every component (header toggle, settings
 * page, toaster) sees the same value without re-reading storage.
 */
const appearance = ref<Appearance>('system');
const systemPrefersDark = ref(false);

const resolvedAppearance = computed<ResolvedAppearance>(() => {
    if (appearance.value === 'system') {
        return systemPrefersDark.value ? 'dark' : 'light';
    }

    return appearance.value;
});

let initialized = false;

const isAppearance = (value: unknown): value is Appearance => value === 'light' || value === 'dark' || value === 'system';

const getStoredAppearance = (): Appearance | null => {
    try {
        const value = localStorage.getItem(STORAGE_KEY);

        return isAppearance(value) ? value : null;
    } catch {
        return null;
    }
};

const setCookie = (name: string, value: string, days = 365) => {
    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const applyTheme = () => {
    const root = document.documentElement;
    const isDark = resolvedAppearance.value === 'dark';

    root.classList.toggle('dark', isDark);
    root.style.colorScheme = isDark ? 'dark' : 'light';
};

/**
 * Kept for backwards compatibility: apply a theme without persisting it.
 */
export function updateTheme(value: Appearance) {
    if (typeof window === 'undefined') {
        return;
    }

    appearance.value = value;
    applyTheme();
}

export function initializeTheme() {
    if (typeof window === 'undefined' || initialized) {
        return;
    }

    initialized = true;

    const media = window.matchMedia('(prefers-color-scheme: dark)');

    systemPrefersDark.value = media.matches;
    appearance.value = getStoredAppearance() ?? 'system';
    applyTheme();

    media.addEventListener('change', (event) => {
        systemPrefersDark.value = event.matches;
        applyTheme();
    });
}

export function useAppearance() {
    initializeTheme();

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // Storage can be unavailable (private mode); the cookie still persists it.
        }

        // The cookie lets the server render the right theme on the first paint.
        setCookie(STORAGE_KEY, value);

        applyTheme();
    }

    return {
        appearance: readonly(appearance),
        resolvedAppearance,
        updateAppearance,
    };
}
