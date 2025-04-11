<script setup lang="ts">
import type { Component } from 'vue';

interface Props {
    title: string;
    icon: Component;
    description?: string;
}

const { title, icon, description } = defineProps<Props>();
</script>

<template>
    <div class="mb-8 space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">
            <component :is="icon" class="inline-block h-5 w-5" />
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </div>
</template>
