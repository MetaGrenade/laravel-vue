<script setup lang="ts">
interface Props {
    title: string;
    description?: string;
}

defineProps<Props>();
</script>

<template>
    <header class="space-y-1 border-b pb-4">
        <!-- Under the page's h1 (the settings and admin layouts carry it), so an h2: an h3 here would skip a level. -->
        <h2 class="text-base font-semibold">{{ title }}</h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
