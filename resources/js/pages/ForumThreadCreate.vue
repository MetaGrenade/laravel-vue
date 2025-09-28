<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';

interface BoardSummary {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    category?: {
        title: string | null;
        slug: string | null;
    } | null;
}

const props = defineProps<{
    board: BoardSummary;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const trail: BreadcrumbItem[] = [{ title: 'Forum', href: '/forum' }];

    if (props.board.category?.title) {
        trail.push({ title: props.board.category.title, href: '/forum' });
    }

    trail.push({ title: props.board.title, href: route('forum.boards.show', { board: props.board.slug }) });
    trail.push({ title: 'Create thread', href: route('forum.threads.create', { board: props.board.slug }) });

    return trail;
});

const form = useForm({
    title: '',
    body: '',
});

const submit = () => {
    form.post(route('forum.threads.store', { board: props.board.slug }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`New Thread • ${props.board.title}`" />

        <form class="mx-auto flex max-w-4xl flex-1 flex-col gap-8 p-4" @submit.prevent="submit">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Start a new discussion</h1>
                    <p class="text-sm text-muted-foreground">
                        Share updates, ask questions, or kick off a new conversation in {{ props.board.title }}.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('forum.boards.show', { board: props.board.slug })">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">Publish thread</Button>
                </div>
            </div>

            <div class="space-y-6 rounded-lg border border-border bg-card p-6 shadow-sm">
                <div class="grid gap-2">
                    <Label for="thread_title">Thread title</Label>
                    <Input
                        id="thread_title"
                        v-model="form.title"
                        type="text"
                        placeholder="Summarise the topic in a sentence"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="thread_body">Message</Label>
                    <Textarea
                        id="thread_body"
                        v-model="form.body"
                        class="min-h-48"
                        placeholder="Share the details, context, or questions to kickstart the discussion."
                        required
                    />
                    <InputError :message="form.errors.body" />
                </div>

                <div v-if="props.board.description" class="rounded-md bg-muted/40 p-4 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">About {{ props.board.title }}</p>
                    <p>{{ props.board.description }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="outline" as-child>
                    <Link :href="route('forum.boards.show', { board: props.board.slug })">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">Publish thread</Button>
            </div>
        </form>
    </AppLayout>
</template>
