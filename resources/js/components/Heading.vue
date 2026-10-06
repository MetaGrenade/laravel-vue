<script setup lang="ts">
import type { Component } from 'vue';

interface Props {
    title: string;
    icon?: Component;
    description?: string;
}

const { title, icon, description } = defineProps<Props>();
</script>

<template>
    <div class="mb-8 flex items-start gap-3">
        <span v-if="icon" class="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-card shadow-xs">
            <component :is="icon" class="size-5 text-primary" />
        </span>
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
    </div>
</template>
