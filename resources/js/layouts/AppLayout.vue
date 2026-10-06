<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/composables/useAppearance';
import AppLayout from '@/layouts/app/AppHeaderLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    /** Let the page span the full viewport width (e.g. marketing pages with their own sections). */
    fullWidth?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fullWidth: false,
});

const { resolvedAppearance } = useAppearance();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :full-width="fullWidth">
        <slot />
    </AppLayout>
    <Toaster :theme="resolvedAppearance" rich-colors close-button />
</template>
