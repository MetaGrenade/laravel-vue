<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/composables/useAppearance';
import AppLayout from '@/layouts/app/AppHeaderLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const { appearance } = useAppearance();
const toasterTheme = computed(() => appearance.value);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
    <Toaster :theme="toasterTheme" rich-colors close-button />
</template>
