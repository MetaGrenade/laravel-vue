<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import ForgeHorizon from '@/components/ForgeHorizon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="bg-page relative flex min-h-svh flex-col overflow-hidden">
        <ForgeHorizon class="absolute inset-x-0 bottom-0 h-44 sm:h-56" />
        <header class="relative flex items-center justify-between p-4 sm:p-6">
            <Link
                :href="route('home')"
                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ArrowLeft class="size-4" />
                Back to site
            </Link>
            <ThemeToggle />
        </header>

        <main class="relative flex flex-1 items-center justify-center px-4 pb-16">
            <div class="w-full max-w-[25rem]">
                <Link :href="route('home')" class="mb-8 flex justify-center rounded-md" aria-label="Home">
                    <AppLogo />
                </Link>

                <div
                    class="rounded-2xl border bg-card/95 p-6 shadow-[0_24px_60px_-32px_hsl(232_50%_20%/0.45)] backdrop-blur-sm sm:p-8 dark:shadow-none"
                >
                    <div v-if="title || description" class="mb-6 space-y-1.5 text-center">
                        <h1 v-if="title" class="text-xl font-semibold tracking-tight">{{ title }}</h1>
                        <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
