<script setup lang="ts">
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Link, usePage } from '@inertiajs/vue3';
import { useDebounceFn, useVModel } from '@vueuse/core';
import { Loader2, Search as SearchIcon } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useGlobalSearchQuery } from '@/composables/useGlobalSearchQuery';
import type { SharedData } from '@/types';

type SearchResultItem = {
    id: number | string;
    title: string;
    description: string | null;
    url: string;
};

type SearchResultGroup = {
    items: SearchResultItem[];
    has_more: boolean;
};

type SearchResultsPayload = {
    query: string;
    results: {
        blogs: SearchResultGroup;
        forum_threads: SearchResultGroup;
        faqs: SearchResultGroup;
    };
};

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const open = useVModel(props, 'open', emit, { passive: true });

const page = usePage<SharedData>();
const websiteSections = computed(() => {
    const defaults = { blog: true, forum: true, support: true } as const;
    const settings = page.props.settings?.website_sections ?? defaults;

    return {
        blog: settings.blog ?? defaults.blog,
        forum: settings.forum ?? defaults.forum,
        support: settings.support ?? defaults.support,
    };
});

const resultGroupToSection = {
    blogs: 'blog',
    forum_threads: 'forum',
    faqs: 'support',
} as const;

const isGroupEnabled = (group: keyof typeof resultGroupToSection): boolean => {
    const section = resultGroupToSection[group];

    return Boolean(websiteSections.value[section]);
};

const query = useGlobalSearchQuery();
const isLoading = ref(false);
const fetchError = ref<string | null>(null);
const inputRef = ref<HTMLInputElement | { $el?: HTMLInputElement } | null>(null);

const createEmptyResults = (): SearchResultsPayload['results'] => ({
    blogs: { items: [], has_more: false },
    forum_threads: { items: [], has_more: false },
    faqs: { items: [], has_more: false },
});

const results = ref<SearchResultsPayload['results']>(createEmptyResults());

const MIN_QUERY_LENGTH = 2;
let activeController: AbortController | null = null;

const trimmedQuery = computed(() => query.value.trim());

const hasAnyResults = computed(() =>
    (isGroupEnabled('blogs') && results.value.blogs.items.length > 0) ||
    (isGroupEnabled('forum_threads') && results.value.forum_threads.items.length > 0) ||
    (isGroupEnabled('faqs') && results.value.faqs.items.length > 0),
);

const groups = computed(() =>
    [
        {
            key: 'blogs',
            title: 'Blog posts',
            items: results.value.blogs.items,
            hasMore: results.value.blogs.has_more,
        },
        {
            key: 'forum_threads',
            title: 'Forum threads',
            items: results.value.forum_threads.items,
            hasMore: results.value.forum_threads.has_more,
        },
        {
            key: 'faqs',
            title: 'FAQs',
            items: results.value.faqs.items,
            hasMore: results.value.faqs.has_more,
        },
    ].filter((group) => group.items.length > 0 && isGroupEnabled(group.key as keyof typeof resultGroupToSection)),
);

const isMac = ref(false);

const resolveInputElement = () => {
    if (inputRef.value instanceof HTMLInputElement) {
        return inputRef.value;
    }

    if (inputRef.value && '$el' in inputRef.value && inputRef.value.$el instanceof HTMLInputElement) {
        return inputRef.value.$el;
    }

    return null;
};

const focusInput = () => {
    void nextTick(() => {
        resolveInputElement()?.focus();
    });
};

const clearActiveRequest = () => {
    if (activeController) {
        activeController.abort();
        activeController = null;
    }
};

const resetResults = () => {
    results.value = createEmptyResults();
};

const performSearch = async (term: string) => {
    clearActiveRequest();

    const controller = new AbortController();
    activeController = controller;
    isLoading.value = true;
    fetchError.value = null;

    try {
        const response = await fetch(route('search', { q: term, limit: 5 }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(`Search request failed with status ${response.status}`);
        }

        const payload = (await response.json()) as SearchResultsPayload;

        results.value = payload?.results ?? createEmptyResults();
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        fetchError.value = 'Unable to load search results. Please try again.';
        resetResults();
    } finally {
        if (activeController === controller) {
            isLoading.value = false;
            activeController = null;
        }
    }
};

const debouncedSearch = useDebounceFn((term: string) => {
    void performSearch(term);
}, 250);

watch(
    () => trimmedQuery.value,
    (value) => {
        if (!open.value) {
            return;
        }

        if (value.length < MIN_QUERY_LENGTH) {
            clearActiveRequest();
            debouncedSearch.cancel?.();
            isLoading.value = false;
            fetchError.value = null;
            resetResults();
            return;
        }

        debouncedSearch(value);
    },
);

watch(
    () => open.value,
    (isOpen) => {
        if (isOpen) {
            focusInput();
            if (trimmedQuery.value.length >= MIN_QUERY_LENGTH) {
                debouncedSearch.cancel?.();
                void performSearch(trimmedQuery.value);
            }

            return;
        }

        clearActiveRequest();
        debouncedSearch.cancel?.();
        fetchError.value = null;
        isLoading.value = false;
        resetResults();
    },
);

const closePalette = () => {
    open.value = false;
};

const handleGlobalKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        closePalette();
    }
};

onMounted(() => {
    isMac.value = /Mac|iPod|iPhone|iPad/.test(window.navigator.platform);
    window.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    clearActiveRequest();
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-5xl gap-0 overflow-hidden p-0">
            <div class="border-b border-border/70 bg-muted/40 px-4 py-3">
                <label class="sr-only" for="global-command-palette">Search</label>
                <div class="flex items-center gap-3">
                    <SearchIcon class="h-4 w-4 text-muted-foreground" />
                    <Input
                        id="global-command-palette"
                        ref="inputRef"
                        v-model="query"
                        type="search"
                        placeholder="Search blogs, forum threads, and FAQs"
                        class="h-9 border-0 bg-transparent p-0 text-base shadow-none focus-visible:ring-0"
                        autocomplete="off"
                        @keydown.esc.prevent="closePalette"
                    />
                    <kbd
                        class="ml-auto mr-5 hidden items-center gap-1 rounded border border-border bg-background px-2 py-0.5 text-[11px] font-medium text-muted-foreground md:inline-flex"
                    >
                        <span>{{ isMac ? '⌘' : 'Ctrl' }}</span>
                        <span>K</span>
                    </kbd>
                </div>
            </div>

            <div class="max-h-[60vh] min-h-[140px] overflow-y-auto">
                <div v-if="fetchError" class="px-4 py-6 text-sm text-destructive">
                    {{ fetchError }}
                </div>
                <div
                    v-else-if="trimmedQuery.length < MIN_QUERY_LENGTH"
                    class="px-4 py-6 text-sm text-muted-foreground"
                >
                    Type at least {{ MIN_QUERY_LENGTH }} characters to search.
                </div>
                <div v-else-if="isLoading" class="flex items-center gap-2 px-4 py-6 text-sm text-muted-foreground">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    Searching…
                </div>
                <template v-else>
                    <div v-if="hasAnyResults" class="divide-y divide-border/60">
                        <section v-for="group in groups" :key="group.key" class="bg-background">
                            <div class="px-4 pt-4 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ group.title }}
                            </div>
                            <ul>
                                <li v-for="item in group.items" :key="`${group.key}-${item.id}`">
                                    <Link
                                        :href="item.url"
                                        class="flex flex-col gap-1 px-4 py-3 text-left transition hover:bg-muted focus:bg-muted focus:outline-none"
                                        @click="closePalette"
                                    >
                                        <span class="text-sm font-medium text-foreground">{{ item.title }}</span>
                                        <span v-if="item.description" class="text-sm text-muted-foreground">
                                            {{ item.description }}
                                        </span>
                                    </Link>
                                </li>
                            </ul>
                            <div v-if="group.hasMore" class="flex items-center gap-1 px-4 pb-3 text-xs text-muted-foreground">
                                <span>Showing top {{ group.items.length }} results.</span>
                                <Link
                                    :href="route('search.results', { q: trimmedQuery, types: [group.key] })"
                                    class="font-medium text-foreground underline-offset-2 hover:underline focus:underline"
                                    @click="closePalette"
                                >
                                    View all
                                </Link>
                            </div>
                        </section>
                    </div>
                    <div v-else class="px-4 py-6 text-sm text-muted-foreground">
                        No results for “{{ trimmedQuery }}”.
                    </div>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
