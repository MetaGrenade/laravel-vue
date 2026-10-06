import { computed, onMounted, readonly, ref } from 'vue';

export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

const STORAGE_KEY = 'appearance';

/*
 * Shared, module-level state so every component (header toggle, settings
 * page, toaster) sees the same value without re-reading storage.
 *
 * The reactive refs start at the values the server renders with ('system',
 * light) and only pick up the stored preference after hydration, so the
 * client's first render matches the SSR markup. The document's `dark` class
 * is applied straight away from the non-reactive copies below, so there is
 * no flash of the wrong theme in the meantime.
 */
const appearance = ref<Appearance>('system');
const systemPrefersDark = ref(false);

const resolvedAppearance = computed<ResolvedAppearance>(() => resolve(appearance.value, systemPrefersDark.value));

let storedAppearance: Appearance = 'system';
let mediaPrefersDark = false;
let initialized = false;
let hydrated = false;

const isAppearance = (value: unknown): value is Appearance => value === 'light' || value === 'dark' || value === 'system';

function resolve(value: Appearance, prefersDark: boolean): ResolvedAppearance {
    if (value === 'system') {
        return prefersDark ? 'dark' : 'light';
    }

    return value;
}

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

/** Apply the current preference to <html>; reads the non-reactive copies so it is safe before hydration. */
const applyTheme = () => {
    const root = document.documentElement;
    const isDark = resolve(storedAppearance, mediaPrefersDark) === 'dark';

    root.classList.toggle('dark', isDark);
    root.style.colorScheme = isDark ? 'dark' : 'light';
};

/** Copy the client-side preference into the reactive state once the SSR markup has been hydrated. */
const syncAfterHydration = () => {
    if (hydrated) {
        return;
    }

    hydrated = true;
    appearance.value = storedAppearance;
    systemPrefersDark.value = mediaPrefersDark;
};

const setAppearance = (value: Appearance) => {
    storedAppearance = value;

    if (hydrated) {
        appearance.value = value;
    }

    applyTheme();
};

/**
 * Kept for backwards compatibility: apply a theme without persisting it.
 */
export function updateTheme(value: Appearance) {
    if (typeof window === 'undefined') {
        return;
    }

    setAppearance(value);
}

export function initializeTheme() {
    if (typeof window === 'undefined' || initialized) {
        return;
    }

    initialized = true;

    const media = window.matchMedia('(prefers-color-scheme: dark)');

    mediaPrefersDark = media.matches;
    storedAppearance = getStoredAppearance() ?? 'system';
    applyTheme();

    media.addEventListener('change', (event) => {
        mediaPrefersDark = event.matches;

        if (hydrated) {
            systemPrefersDark.value = event.matches;
        }

        applyTheme();
    });
}

export function useAppearance() {
    initializeTheme();

    // Mounted hooks run after hydration has finished, so updating the shared
    // state here re-renders consumers without a hydration mismatch.
    onMounted(syncAfterHydration);

    function updateAppearance(value: Appearance) {
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // Storage can be unavailable (private mode); the cookie still persists it.
        }

        // The cookie lets the server render the right theme on the first paint.
        setCookie(STORAGE_KEY, value);

        setAppearance(value);
    }

    return {
        appearance: readonly(appearance),
        resolvedAppearance,
        updateAppearance,
    };
}
