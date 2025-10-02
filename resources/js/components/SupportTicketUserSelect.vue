<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type UserOption = {
    id: number;
    nickname: string;
    email: string;
};

const props = defineProps<{
    modelValue: number | null;
    initialUser?: UserOption | null;
    inputId?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
    (e: 'change', value: UserOption | null): void;
}>();

const searchQuery = ref('');
const results = ref<UserOption[]>([]);
const selectedUser = ref<UserOption | null>(props.initialUser ?? null);
const isLoading = ref(false);
const fetchError = ref<string | null>(null);

const MIN_QUERY_LENGTH = 2;
let debounceHandle: ReturnType<typeof setTimeout> | undefined;
let activeController: AbortController | null = null;

const clearActiveRequest = () => {
    if (activeController) {
        activeController.abort();
        activeController = null;
    }
};

const resetSearchState = () => {
    results.value = [];
    isLoading.value = false;
};

const selectUser = (user: UserOption) => {
    selectedUser.value = user;
    emit('update:modelValue', user.id);
    emit('change', user);
    searchQuery.value = '';
    results.value = [];
    fetchError.value = null;
};

const clearSelection = () => {
    selectedUser.value = null;
    emit('update:modelValue', null);
    emit('change', null);
    searchQuery.value = '';
    results.value = [];
    fetchError.value = null;
};

const performSearch = async (term: string) => {
    clearActiveRequest();

    const controller = new AbortController();
    activeController = controller;
    isLoading.value = true;
    fetchError.value = null;

    try {
        const response = await fetch(route('acp.support.users.search', { q: term }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(`Search request failed with status ${response.status}`);
        }

        const payload = (await response.json()) as { data?: UserOption[] };
        results.value = payload.data ?? [];
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        results.value = [];
        fetchError.value = 'Unable to load users. Please try again.';
    } finally {
        isLoading.value = false;
        activeController = null;
    }
};

const loadUserById = async (id: number) => {
    try {
        const response = await fetch(route('acp.support.users.search', { id }), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`Lookup request failed with status ${response.status}`);
        }

        const payload = (await response.json()) as { data?: UserOption[] };
        const match = payload.data?.[0] ?? null;

        if (match) {
            selectedUser.value = match;
        }
    } catch {
        // Ignore lookup failures and leave the selection unset.
    }
};

watch(
    () => props.modelValue,
    (value) => {
        if (value === null || typeof value === 'undefined') {
            selectedUser.value = null;
            return;
        }

        if (selectedUser.value?.id === value) {
            return;
        }

        const fromResults = results.value.find((user) => user.id === value);
        if (fromResults) {
            selectedUser.value = fromResults;
            return;
        }

        if (props.initialUser && props.initialUser.id === value) {
            selectedUser.value = props.initialUser;
            return;
        }

        void loadUserById(value);
    },
    { immediate: true },
);

watch(
    () => props.initialUser,
    (user) => {
        if (!user) {
            return;
        }

        if (props.modelValue === user.id) {
            selectedUser.value = user;
        }
    },
);

watch(
    () => searchQuery.value,
    (value) => {
        if (debounceHandle) {
            clearTimeout(debounceHandle);
            debounceHandle = undefined;
        }

        const trimmed = value.trim();

        if (trimmed.length < MIN_QUERY_LENGTH) {
            clearActiveRequest();
            resetSearchState();
            fetchError.value = null;
            return;
        }

        debounceHandle = setTimeout(() => {
            void performSearch(trimmed);
        }, 300);
    },
);

onBeforeUnmount(() => {
    if (debounceHandle) {
        clearTimeout(debounceHandle);
    }

    clearActiveRequest();
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-col gap-2 sm:flex-row">
            <Input
                :id="inputId"
                v-model="searchQuery"
                type="search"
                placeholder="Search by nickname or email"
                class="sm:flex-1"
                autocomplete="off"
            />
            <Button type="button" variant="outline" @click="clearSelection" :disabled="!selectedUser && !searchQuery">
                Clear
            </Button>
        </div>

        <div v-if="selectedUser" class="rounded-md border border-border bg-muted/40 p-3 text-sm">
            <div class="font-medium text-foreground">{{ selectedUser.nickname }}</div>
            <div class="text-muted-foreground">{{ selectedUser.email }}</div>
        </div>

        <p v-if="fetchError" class="text-sm text-destructive">{{ fetchError }}</p>
        <p v-else-if="isLoading" class="text-sm text-muted-foreground">Searching for users…</p>

        <ul v-else-if="results.length" class="divide-y rounded-md border border-border">
            <li v-for="user in results" :key="user.id">
                <button
                    type="button"
                    class="flex w-full flex-col items-start gap-1 px-3 py-2 text-left hover:bg-muted"
                    @click="selectUser(user)"
                >
                    <span class="font-medium text-foreground">{{ user.nickname }}</span>
                    <span class="text-sm text-muted-foreground">{{ user.email }}</span>
                </button>
            </li>
        </ul>

        <p
            v-else-if="searchQuery.trim().length >= MIN_QUERY_LENGTH"
            class="text-sm text-muted-foreground"
        >
            No users found for “{{ searchQuery.trim() }}”.
        </p>
    </div>
</template>
