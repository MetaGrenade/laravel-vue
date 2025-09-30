<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import MarkdownComposer from '@/components/editor/MarkdownComposer.vue';
import { StorageSerializers, useDebounceFn, useLocalStorage } from '@vueuse/core';
import dayjs from 'dayjs';

interface ThreadDraft {
    title: string;
    body: string;
    updatedAt: number;
}

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

const draftStorageKey = `forum:board:${props.board.id}:thread-draft`;
const draft = useLocalStorage<ThreadDraft | null>(draftStorageKey, null, {
    serializer: StorageSerializers.object,
    initOnMounted: true,
});

const hasHydratedDraft = ref(false);
const pendingHydrationUpdates = ref(0);
const lastSavedAt = ref<number | null>(null);

watch(
    draft,
    (value) => {
        if (!hasHydratedDraft.value) {
            if (value?.title && !form.title) {
                pendingHydrationUpdates.value += 1;
                form.title = value.title;
            }

            if (value?.body && !form.body) {
                pendingHydrationUpdates.value += 1;
                form.body = value.body;
            }

            hasHydratedDraft.value = true;
        }

        lastSavedAt.value = value?.updatedAt ?? null;
    },
    { immediate: true }
);

const persistDraft = useDebounceFn(() => {
    if (!hasHydratedDraft.value) {
        return;
    }

    const trimmedTitle = form.title.trim();
    const trimmedBody = form.body.trim();

    if (!trimmedTitle && !trimmedBody) {
        draft.value = null;
        lastSavedAt.value = null;
        return;
    }

    const payload: ThreadDraft = {
        title: form.title,
        body: form.body,
        updatedAt: Date.now(),
    };

    draft.value = payload;
    lastSavedAt.value = payload.updatedAt;
}, 800);

watch(
    () => [form.title, form.body],
    () => {
        if (pendingHydrationUpdates.value > 0) {
            pendingHydrationUpdates.value -= 1;
            return;
        }

        persistDraft();
    }
);

onBeforeUnmount(() => {
    persistDraft.cancel?.();
});

const clearDraft = () => {
    persistDraft.cancel?.();
    draft.value = null;
    lastSavedAt.value = null;
};

const autosaveStatus = computed(() => {
    if (!lastSavedAt.value) {
        return 'Drafts save automatically.';
    }

    const diffInMinutes = dayjs().diff(lastSavedAt.value, 'minute');

    if (diffInMinutes < 1) {
        return 'Draft saved just now.';
    }

    return `Draft saved at ${dayjs(lastSavedAt.value).format('h:mm A')}.`;
});

const submit = () => {
    persistDraft.flush?.();
    form.post(route('forum.threads.store', { board: props.board.slug }), {
        preserveScroll: true,
        onSuccess: () => {
            clearDraft();
        },
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
                    <MarkdownComposer
                        id="thread_body"
                        v-model="form.body"
                        :rows="14"
                        placeholder="Share the details, context, or questions to kickstart the discussion."
                        required
                    >
                        <template #footer>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>Markdown formatting is supported.</span>
                                <span>{{ autosaveStatus }}</span>
                            </div>
                        </template>
                    </MarkdownComposer>
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
