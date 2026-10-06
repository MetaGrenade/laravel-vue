<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    fullWidth?: boolean;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fullWidth: false,
});
</script>

<template>
    <AppShell variant="header">
        <a
            href="#main-content"
            class="sr-only z-50 rounded-md bg-background px-4 py-2 text-sm font-medium shadow focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >
            Skip to content
        </a>
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent :full-width="fullWidth">
            <slot />
        </AppContent>
        <AppFooter />
    </AppShell>
</template>
