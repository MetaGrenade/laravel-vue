<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

import { Toaster } from '@/components/ui/sonner';
import type { SharedData } from '@/types';

type FlashMessages = {
    success?: string | null;
    error?: string | null;
    info?: string | null;
};

const page = usePage<SharedData & { flash?: FlashMessages }>();
const toasterOptions = { richColors: true, closeButton: true } as const;
const isDarkMode = ref(false);
let mutationObserver: MutationObserver | null = null;

const syncDarkMode = () => {
    if (typeof document === 'undefined') {
        return;
    }

    isDarkMode.value = document.documentElement.classList.contains('dark');
};

if (typeof document !== 'undefined') {
    syncDarkMode();
}

onMounted(() => {
    if (typeof document === 'undefined') {
        return;
    }

    mutationObserver = new MutationObserver(syncDarkMode);
    mutationObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onBeforeUnmount(() => {
    mutationObserver?.disconnect();
});

const toasterTheme = computed<'dark' | 'light'>(() => (isDarkMode.value ? 'dark' : 'light'));

const flashSuccess = computed(() => page.props.flash?.success ?? '');
const flashError = computed(() => page.props.flash?.error ?? '');
const flashInfo = computed(() => page.props.flash?.info ?? '');
const inertiaErrors = computed<Record<string, unknown>>(() => page.props.errors ?? {});

watch(
    flashSuccess,
    (message, previous) => {
        if (message && message !== previous) {
            toast.success(message);
        }
    },
    { immediate: true },
);

watch(
    flashError,
    (message, previous) => {
        if (message && message !== previous) {
            toast.error(message);
        }
    },
    { immediate: true },
);

watch(
    flashInfo,
    (message, previous) => {
        if (message && message !== previous) {
            toast(message);
        }
    },
    { immediate: true },
);

watch(
    inertiaErrors,
    (errors, previous) => {
        const hasErrors = Object.keys(errors).length > 0;
        const hadErrors = previous ? Object.keys(previous).length > 0 : false;

        if (hasErrors && !hadErrors) {
            toast.error('There were some problems with your submission.');
        }
    },
    { deep: true },
);
</script>

<template>
    <Toaster v-bind="toasterOptions" :theme="toasterTheme" />
</template>
