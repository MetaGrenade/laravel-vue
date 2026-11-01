<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Home, RotateCw } from '@lucide/vue';

// The words come with the page (App\Support\Errors\ErrorPages): the error copy is a Laravel group that is not sent with every page.
defineProps<{
    status: number;
    hint: string;
    detail: string | null;
    retryable: boolean;
    labels: { home: string; back: string; retry: string };
}>();

/** A page opened in a new tab has nothing to go back to. */
function goBack() {
    if (window.history.length > 1) {
        window.history.back();

        return;
    }

    router.visit(route('home'));
}
</script>

<template>
    <AppLayout>
        <Head :title="String(status)" />

        <section class="mx-auto flex w-full max-w-xl flex-col items-start gap-4 px-4 py-16">
            <p class="flex items-center gap-3 font-mono text-sm font-semibold tracking-[0.2em] text-primary">
                <span class="h-px w-8 bg-primary" aria-hidden="true" />{{ status }}
            </p>

            <h1 class="text-3xl font-semibold tracking-tight text-balance">{{ hint }}</h1>
            <p v-if="detail" class="text-muted-foreground">{{ detail }}</p>

            <div class="mt-2 flex flex-wrap gap-3">
                <Button v-if="retryable" class="cursor-pointer" @click="router.reload()"> <RotateCw class="size-4" />{{ labels.retry }} </Button>
                <Button variant="outline" class="cursor-pointer" @click="goBack"> <ArrowLeft class="size-4" />{{ labels.back }} </Button>
                <Button variant="outline" as-child>
                    <Link :href="route('home')"><Home class="size-4" />{{ labels.home }}</Link>
                </Button>
            </div>
        </section>
    </AppLayout>
</template>
