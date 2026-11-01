<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Scale } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    slug: string;
    title: string;
    body: string;
    updated_at: string | null;
}>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [{ title: props.title, href: route('legal.show', { page: props.slug }) }]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <article class="mx-auto w-full max-w-3xl space-y-6 p-4">
            <header class="flex items-center gap-4 rounded-xl border bg-card p-5 shadow-xs">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <Scale class="size-5" />
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                    <p v-if="updated_at" class="text-sm text-muted-foreground">{{ t('legal.updated', { date: updated_at }) }}</p>
                </div>
            </header>

            <div class="prose max-w-none rounded-xl border bg-card p-5 shadow-xs" v-html="body" />
        </article>
    </AppLayout>
</template>
