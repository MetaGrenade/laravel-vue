<script setup lang="ts">
import dayjs from 'dayjs';
import { ref, computed, watch, onBeforeUnmount, reactive } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Ellipsis, Trash2, Pencil, Coins, ShieldCheck, ShieldAlert, ShieldOff, Ban } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useConfirmDialog } from '@/composables/useConfirmDialog';

// dayjs composable for human readable dates
const { fromNow } = useUserTimezone();

// Permission checks
const { hasPermission } = usePermissions();
const createTokens = computed(() => hasPermission('tokens.acp.create'));
const editTokens = computed(() => hasPermission('tokens.acp.edit'));
const deleteTokens = computed(() => hasPermission('tokens.acp.delete'));

// Dummy breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens', href: '/acp/tokens' }
];

interface TokenUser {
    id: number;
    nickname: string;
    email: string;
}

interface Token {
    id: number;
    name: string;
    user: TokenUser | null;
    created_at: string | null;
    last_used_at?: string | null;
    expires_at?: string | null;
    revoked_at?: string | null;
    abilities?: string[];
}

interface TokenLog {
    id: number;
    token_name: string | null;
    api_route: string;
    method: string;
    status: string;
    http_status: number | null;
    timestamp: string | null;
}

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

interface TokenLogFilters {
    token?: string | null;
    status?: string | null;
    date_from?: string | null;
    date_to?: string | null;
    per_page?: number | null;
}

const props = defineProps<{
    tokens: {
        data: Token[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    tokenStats: {
        total: number;
        active: number;
        expired: number;
        revoked: number;
    };
    userList: Array<{
        id: number;
        nickname: string;
        email: string;
    }>;
    tokenLogs: {
        data: TokenLog[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    logFilters?: TokenLogFilters | null;
}>();

const page = usePage<SharedData & { flash?: { plain_text_token?: string | null; success?: string | null; error?: string | null } }>();
const flashPlainTextToken = computed(() => page.props.flash?.plain_text_token ?? '');
const tokenSecretDialogOpen = ref(false);
const tokenSecretValue = ref('');
const tokenSecretCopied = ref(false);
const tokenSecretCopyError = ref<string | null>(null);
let tokenSecretCopyTimeout: ReturnType<typeof setTimeout> | null = null;

watch(flashPlainTextToken, (value) => {
    if (value) {
        tokenSecretValue.value = value;
        tokenSecretDialogOpen.value = true;
        tokenSecretCopied.value = false;
        tokenSecretCopyError.value = null;
    }
}, { immediate: true });

watch(tokenSecretDialogOpen, (open) => {
    if (!open && tokenSecretCopyTimeout) {
        clearTimeout(tokenSecretCopyTimeout);
        tokenSecretCopyTimeout = null;
        tokenSecretCopied.value = false;
    }
});

const copyTokenSecret = async () => {
    if (!tokenSecretValue.value) {
        return;
    }

    tokenSecretCopyError.value = null;

    if (
        typeof navigator === 'undefined' ||
        !navigator.clipboard ||
        typeof navigator.clipboard.writeText !== 'function'
    ) {
        tokenSecretCopyError.value = 'Copying is not supported in this browser. Please copy the token manually.';
        return;
    }

    try {
        await navigator.clipboard.writeText(tokenSecretValue.value);
        tokenSecretCopied.value = true;
        tokenSecretCopyError.value = null;

        if (tokenSecretCopyTimeout) {
            clearTimeout(tokenSecretCopyTimeout);
        }

        tokenSecretCopyTimeout = window.setTimeout(() => {
            tokenSecretCopied.value = false;
            tokenSecretCopyTimeout = null;
        }, 2000);
    } catch (error) {
        console.error('Failed to copy token secret', error);
        tokenSecretCopied.value = false;
        tokenSecretCopyError.value = 'Unable to copy the token automatically. Please copy it manually.';
    }
};

onBeforeUnmount(() => {
    if (tokenSecretCopyTimeout) {
        clearTimeout(tokenSecretCopyTimeout);
        tokenSecretCopyTimeout = null;
    }
});

const tokensMetaSource = computed(() => props.tokens.meta ?? null);
const tokenItems = computed(() => props.tokens.data ?? []);

const LOGS_PER_PAGE_DEFAULT = 25;
const LOGS_PER_PAGE_OPTIONS = [10, 25, 50, 100];

const logFiltersState = reactive({
    token: props.logFilters?.token ?? '',
    status: props.logFilters?.status ?? '',
    date_from: props.logFilters?.date_from ?? '',
    date_to: props.logFilters?.date_to ?? '',
});

const initialLogsPerPage = props.logFilters?.per_page
    ?? props.tokenLogs.meta?.per_page
    ?? LOGS_PER_PAGE_DEFAULT;

const logsPerPage = ref(Number.isFinite(initialLogsPerPage) && initialLogsPerPage ? initialLogsPerPage : LOGS_PER_PAGE_DEFAULT);

const tokenLogsMetaSource = computed(() => props.tokenLogs.meta ?? null);
const tokenLogsItems = computed(() => props.tokenLogs.data ?? []);

watch(
    () => props.logFilters,
    (filters) => {
        logFiltersState.token = filters?.token ?? '';
        logFiltersState.status = filters?.status ?? '';
        logFiltersState.date_from = filters?.date_from ?? '';
        logFiltersState.date_to = filters?.date_to ?? '';

        if (typeof filters?.per_page === 'number' && filters.per_page > 0) {
            logsPerPage.value = filters.per_page;
        }
    },
    { deep: true },
);

watch(
    () => props.tokenLogs.meta?.per_page,
    (perPage) => {
        if (typeof perPage === 'number' && perPage > 0) {
            logsPerPage.value = perPage;
        }
    },
);

const availableLogStatuses = computed(() => {
    const statuses = new Set<string>(['success', 'failed']);

    if (logFiltersState.status) {
        statuses.add(logFiltersState.status);
    }

    tokenLogsItems.value.forEach((log) => {
        if (log.status) {
            statuses.add(log.status);
        }
    });

    return Array.from(statuses).sort((a, b) => a.localeCompare(b));
});

const {
    meta: tokensMeta,
    page: tokensPage,
    setPage: setTokensPage,
    rangeLabel: tokensRangeLabel,
} = useInertiaPagination({
    meta: tokensMetaSource,
    itemsLength: computed(() => tokenItems.value.length),
    defaultPerPage: 10,
    itemLabel: 'token',
    itemLabelPlural: 'tokens',
    onNavigate: (page) => {
        router.get(
            route('acp.tokens.index'),
            buildQueryParams({ page }),
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

const {
    meta: tokenLogsMeta,
    page: tokenLogsPage,
    setPage: setTokenLogsPage,
    rangeLabel: tokenLogsRangeLabel,
} = useInertiaPagination({
    meta: tokenLogsMetaSource,
    itemsLength: computed(() => tokenLogsItems.value.length),
    defaultPerPage: logsPerPage.value || LOGS_PER_PAGE_DEFAULT,
    itemLabel: 'log entry',
    itemLabelPlural: 'log entries',
    onNavigate: (page) => {
        router.get(
            route('acp.tokens.index'),
            buildQueryParams({ logs_page: page }),
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

function cleanQuery(query: Record<string, unknown>) {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => {
            if (value === null || value === undefined) {
                return false;
            }

            if (typeof value === 'string') {
                return value.trim() !== '';
            }

            return true;
        }),
    );
}

function buildQueryParams(overrides: Record<string, unknown> = {}) {
    return cleanQuery({
        page: tokensPage.value,
        logs_page: tokenLogsPage.value,
        logs_per_page: logsPerPage.value,
        token: logFiltersState.token,
        status: logFiltersState.status,
        date_from: logFiltersState.date_from,
        date_to: logFiltersState.date_to,
        ...overrides,
    });
}

const applyLogFilters = (overrides: Record<string, unknown> = {}) => {
    setTokenLogsPage(1, { emitNavigate: false });

    router.get(
        route('acp.tokens.index'),
        buildQueryParams({ logs_page: 1, ...overrides }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const resetLogFilters = () => {
    logFiltersState.token = '';
    logFiltersState.status = '';
    logFiltersState.date_from = '';
    logFiltersState.date_to = '';
    logsPerPage.value = LOGS_PER_PAGE_DEFAULT;

    applyLogFilters({ logs_per_page: LOGS_PER_PAGE_DEFAULT });
};

const onLogsPerPageChange = (value: number) => {
    const nextValue = Number.isFinite(value) && value > 0 ? value : LOGS_PER_PAGE_DEFAULT;
    logsPerPage.value = nextValue;
    applyLogFilters({ logs_per_page: nextValue });
};

const showTokenLogsPagination = computed(
    () => tokenLogsMeta.value.total > tokenLogsMeta.value.per_page,
);

const totalTokens = computed(() => props.tokenStats.total);
const activeTokens = computed(() => props.tokenStats.active);
const expiredTokens = computed(() => props.tokenStats.expired);
const revokedTokens = computed(() => props.tokenStats.revoked);

// Search query and filtering for token list
const tokenSearchQuery = ref('');
const filteredTokens = computed(() => {
    if (!tokenSearchQuery.value) {
        return tokenItems.value;
    }
    const q = tokenSearchQuery.value.toLowerCase();
    return tokenItems.value.filter((token) => {
        const matchesName = token.name.toLowerCase().includes(q);
        const matchesNickname = token.user?.nickname?.toLowerCase().includes(q) ?? false;
        const matchesEmail = token.user?.email?.toLowerCase().includes(q) ?? false;

        return matchesName || matchesNickname || matchesEmail;
    });
});

watch(tokenSearchQuery, () => {
    setTokensPage(1, { emitNavigate: false });
});

const showTokenPagination = computed(
    () => tokensMeta.value.total > tokensMeta.value.per_page,
);

// Create token dialog state & form
const createDialogOpen = ref(false);
const defaultUserId = props.userList.length ? props.userList[0].id : '';
const createTokenForm = useForm({
    name: '',
    user_id: defaultUserId as number | '',
    expires_at: '',
    abilities: [] as string[],
});
const abilityInput = ref('');

const resetCreateTokenForm = () => {
    createTokenForm.reset();
    abilityInput.value = '';
    createTokenForm.clearErrors();
};

watch(createDialogOpen, (open) => {
    if (!open) {
        resetCreateTokenForm();
    }
});

const submitCreateToken = () => {
    createTokenForm.abilities = abilityInput.value
        .split(',')
        .map((ability) => ability.trim())
        .filter(Boolean);

    createTokenForm.post(route('acp.tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createDialogOpen.value = false;
        },
    });
};

const editDialogOpen = ref(false);
const editingTokenId = ref<number | null>(null);
const editingToken = ref<Token | null>(null);
const editTokenForm = useForm({
    name: '',
    expires_at: '',
    abilities: [] as string[],
    clear_revocation: false,
});
const editAbilityInput = ref('');

const resetEditTokenForm = () => {
    editTokenForm.reset();
    editTokenForm.abilities = [];
    editTokenForm.clear_revocation = false;
    editTokenForm.clearErrors();
    editAbilityInput.value = '';
    editingTokenId.value = null;
    editingToken.value = null;
};

watch(editDialogOpen, (open) => {
    if (!open) {
        resetEditTokenForm();
    }
});

const openEditDialog = (token: Token) => {
    editingTokenId.value = token.id;
    editingToken.value = token;
    editTokenForm.name = token.name;
    editTokenForm.expires_at = token.expires_at
        ? dayjs(token.expires_at).format('YYYY-MM-DDTHH:mm')
        : '';
    editTokenForm.clear_revocation = false;
    editAbilityInput.value = (token.abilities ?? []).join(', ');
    editTokenForm.clearErrors();
    editDialogOpen.value = true;
};

const submitEditToken = () => {
    if (!editingTokenId.value) {
        return;
    }

    editTokenForm.abilities = editAbilityInput.value
        .split(',')
        .map((ability) => ability.trim())
        .filter(Boolean);

    editTokenForm.put(route('acp.tokens.update', { token: editingTokenId.value }), {
        preserveScroll: true,
        onSuccess: () => {
            editDialogOpen.value = false;
        },
    });
};

const deleteForm = useForm({});
const deleteToken = (tokenId: number) => {
    deleteForm.delete(route('acp.tokens.destroy', { token: tokenId }), {
        preserveScroll: true,
    });
};

const revokeForm = useForm({});
const revokeToken = (tokenId: number) => {
    revokeForm.patch(route('acp.tokens.revoke', { token: tokenId }), {
        preserveScroll: true,
    });
};

const resolveTokenStatus = (token: Token) => {
    if (token.revoked_at) {
        return {
            label: 'Revoked',
            classes:
                'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-200',
        };
    }

    if (token.expires_at && dayjs(token.expires_at).isBefore(dayjs())) {
        return {
            label: 'Expired',
            classes:
                'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-200',
        };
    }

    return {
        label: 'Active',
        classes:
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200',
    };
};

const {
    confirmDialogState,
    confirmDialogDescription,
    openConfirmDialog,
    handleConfirmDialogConfirm,
    handleConfirmDialogCancel,
} = useConfirmDialog();

const requestTokenRevocation = (token: Token) => {
    if (revokeForm.processing || token.revoked_at) {
        return;
    }

    const tokenLabel = token.name.trim() !== '' ? `“${token.name}”` : `token #${token.id}`;

    openConfirmDialog({
        title: `Revoke ${tokenLabel}?`,
        description: `Revoking ${tokenLabel} will immediately prevent further API access using this credential.`,
        confirmLabel: 'Revoke token',
        onConfirm: () => revokeToken(token.id),
    });
};

const requestTokenDeletion = (token: Token) => {
    const tokenLabel = token.name.trim() !== '' ? `“${token.name}”` : `token #${token.id}`;

    openConfirmDialog({
        title: `Delete ${tokenLabel}?`,
        description: `Deleting ${tokenLabel} will remove its access history. This action cannot be undone.`,
        confirmLabel: 'Delete token',
        onConfirm: () => deleteToken(token.id),
    });
};

const lastUsedDisplay = (value?: string | null) => {
    if (!value) {
        return 'Never';
    }

    return fromNow(value);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tokens Management" />
        <AdminLayout>
            <div class="container mx-auto p-4 space-y-8">
                <!-- Stats Section -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-1 lg:grid-cols-4">
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
                            <component :is="Coins" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Total Tokens</div>
                            <div class="text-xl font-bold">{{ totalTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
                            <component :is="ShieldCheck" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Active Tokens</div>
                            <div class="text-xl font-bold">{{ activeTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
                            <component :is="ShieldAlert" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Expired Tokens</div>
                            <div class="text-xl font-bold">{{ expiredTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
                            <component :is="ShieldOff" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Revoked Tokens</div>
                            <div class="text-xl font-bold">{{ revokedTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Tabs for Token List and Token Logs -->
                <Tabs default-value="tokens" class="w-full">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <TabsList>
                            <TabsTrigger value="tokens">Token List</TabsTrigger>
                            <TabsTrigger value="logs">Token Activity</TabsTrigger>
                        </TabsList>
                    <div class="flex space-x-2">
                        <Dialog v-model:open="tokenSecretDialogOpen">
                            <DialogContent class="sm:max-w-lg">
                                <DialogHeader class="space-y-2">
                                    <DialogTitle>Save your new token</DialogTitle>
                                    <DialogDescription>
                                        This is the only time the token secret will be shown. Copy and store it securely now.
                                    </DialogDescription>
                                </DialogHeader>

                                <div class="space-y-3">
                                    <Label for="new-token-secret">Token secret</Label>
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        <Textarea
                                            id="new-token-secret"
                                            v-model="tokenSecretValue"
                                            rows="3"
                                            readonly
                                            class="font-mono text-sm"
                                        />
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            class="shrink-0 sm:h-auto sm:w-32"
                                            @click="copyTokenSecret"
                                        >
                                            {{ tokenSecretCopied ? 'Copied!' : 'Copy token' }}
                                        </Button>
                                    </div>
                                    <p
                                        v-if="tokenSecretCopied"
                                        class="text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                    >
                                        Token copied to clipboard.
                                    </p>
                                    <p
                                        v-else-if="tokenSecretCopyError"
                                        class="text-xs text-red-600 dark:text-red-400"
                                    >
                                        {{ tokenSecretCopyError }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Make sure to store this token securely. You won't be able to see it again after closing this dialog.
                                    </p>
                                </div>

                                <DialogFooter class="gap-2 sm:gap-4">
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline">Close</Button>
                                    </DialogClose>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        <Dialog v-if="createTokens" v-model:open="createDialogOpen">
                            <DialogTrigger as-child>
                                <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600 md:ml-10">
                                    Create Token
                                    </Button>
                                </DialogTrigger>
                                <DialogContent class="sm:max-w-lg">
                                    <form class="space-y-6" @submit.prevent="submitCreateToken">
                                        <DialogHeader class="space-y-2">
                                            <DialogTitle>Create access token</DialogTitle>
                                            <DialogDescription>
                                                Generate a new personal access token and assign it to a user.
                                            </DialogDescription>
                                        </DialogHeader>

                                        <div class="space-y-4">
                                            <div class="space-y-2">
                                                <Label for="token-name">Token name</Label>
                                                <Input
                                                    id="token-name"
                                                    v-model="createTokenForm.name"
                                                    type="text"
                                                    placeholder="Server integration"
                                                    autocomplete="off"
                                                    required
                                                />
                                                <InputError :message="createTokenForm.errors.name" />
                                            </div>

                                            <div class="space-y-2">
                                                <Label for="token-user">User</Label>
                                                <select
                                                    id="token-user"
                                                    v-model="createTokenForm.user_id"
                                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700"
                                                    required
                                                >
                                                    <option disabled value="">Select a user</option>
                                                    <option
                                                        v-for="user in userList"
                                                        :key="user.id"
                                                        :value="user.id"
                                                    >
                                                        {{ user.nickname }} ({{ user.email }})
                                                    </option>
                                                </select>
                                                <InputError :message="createTokenForm.errors.user_id" />
                                            </div>

                                            <div class="space-y-2">
                                                <Label for="token-abilities">Abilities</Label>
                                                <Textarea
                                                    id="token-abilities"
                                                    v-model="abilityInput"
                                                    rows="3"
                                                    placeholder="Comma separated abilities (leave blank for full access)"
                                                />
                                                <p class="text-xs text-muted-foreground">
                                                    Leave empty to grant full access. Example: read,update
                                                </p>
                                                <InputError :message="createTokenForm.errors.abilities" />
                                            </div>

                                            <div class="space-y-2">
                                                <Label for="token-expires-at">Expires at</Label>
                                                <Input
                                                    id="token-expires-at"
                                                    v-model="createTokenForm.expires_at"
                                                    type="datetime-local"
                                                />
                                                <InputError :message="createTokenForm.errors.expires_at" />
                                            </div>
                                        </div>

                                        <DialogFooter class="gap-2 sm:gap-4">
                                            <DialogClose as-child>
                                                <Button type="button" variant="outline">Cancel</Button>
                                            </DialogClose>
                                            <Button type="submit" :disabled="createTokenForm.processing">
                                                {{ createTokenForm.processing ? 'Creating…' : 'Create token' }}
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                            <Dialog v-if="editTokens" v-model:open="editDialogOpen">
                                <DialogContent class="sm:max-w-lg">
                                    <form class="space-y-6" @submit.prevent="submitEditToken">
                                        <DialogHeader class="space-y-2">
                                            <DialogTitle>Edit access token</DialogTitle>
                                            <DialogDescription>
                                                Update the token name, expiry and abilities.
                                            </DialogDescription>
                                        </DialogHeader>

                                        <div class="space-y-4">
                                            <div class="space-y-2">
                                                <Label for="edit-token-name">Token name</Label>
                                                <Input
                                                    id="edit-token-name"
                                                    v-model="editTokenForm.name"
                                                    type="text"
                                                    placeholder="Server integration"
                                                    autocomplete="off"
                                                    required
                                                />
                                                <InputError :message="editTokenForm.errors.name" />
                                            </div>

                                            <div class="space-y-2">
                                                <Label for="edit-token-abilities">Abilities</Label>
                                                <Textarea
                                                    id="edit-token-abilities"
                                                    v-model="editAbilityInput"
                                                    rows="3"
                                                    placeholder="Comma separated abilities (leave blank for full access)"
                                                />
                                                <p class="text-xs text-muted-foreground">
                                                    Leave empty to grant full access. Example: read,update
                                                </p>
                                                <InputError :message="editTokenForm.errors.abilities" />
                                            </div>

                                            <div class="space-y-2">
                                                <Label for="edit-token-expires-at">Expires at</Label>
                                                <Input
                                                    id="edit-token-expires-at"
                                                    v-model="editTokenForm.expires_at"
                                                    type="datetime-local"
                                                />
                                                <InputError :message="editTokenForm.errors.expires_at" />
                                            </div>

                                            <div
                                                v-if="editingToken?.revoked_at"
                                                class="flex items-center space-x-2 rounded-md border border-dashed border-muted p-3"
                                            >
                                                <Checkbox
                                                    id="restore-token"
                                                    v-model:checked="editTokenForm.clear_revocation"
                                                />
                                                <Label for="restore-token" class="text-sm leading-tight">
                                                    Restore this token (clear revoked status)
                                                </Label>
                                            </div>
                                        </div>

                                        <DialogFooter class="gap-2 sm:gap-4">
                                            <DialogClose as-child>
                                                <Button type="button" variant="outline">Cancel</Button>
                                            </DialogClose>
                                            <Button type="submit" :disabled="editTokenForm.processing">
                                                {{ editTokenForm.processing ? 'Saving…' : 'Save changes' }}
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>

                    <!-- Token List Tab -->
                    <TabsContent value="tokens" class="space-y-6">
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                            <!-- Search Bar -->
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                                <h2 class="text-lg font-semibold mb-2 md:mb-0">Manage Access Tokens</h2>
                                <div class="flex space-x-2">
                                    <Input
                                        v-model="tokenSearchQuery"
                                        placeholder="Search tokens..."
                                        class="w-full rounded-md"
                                    />
                                </div>
                            </div>
                            <!-- Tokens Table -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Token Name</TableHead>
                                            <TableHead>Assigned To</TableHead>
                                            <TableHead class="text-center">Created</TableHead>
                                            <TableHead class="text-center">Last Used</TableHead>
                                            <TableHead class="text-center">Status</TableHead>
                                            <TableHead class="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="token in filteredTokens"
                                            :key="token.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                        >
                                            <TableCell>{{ token.id }}</TableCell>
                                            <TableCell>{{ token.name }}</TableCell>
                                            <TableCell>
                                                {{ token.user?.nickname ?? '—' }}<br />
                                                <span class="text-xs text-gray-500">{{ token.user?.email ?? '—' }}</span>
                                            </TableCell>
                                            <TableCell class="text-center">{{ token.created_at ? fromNow(token.created_at) : '—' }}</TableCell>
                                            <TableCell class="text-center">{{ lastUsedDisplay(token.last_used_at) }}</TableCell>
                                            <TableCell class="text-center">
                                                <span
                                                    :class="[
                                                        'inline-flex items-center justify-center rounded-full px-2 py-1 text-xs font-medium capitalize',
                                                        resolveTokenStatus(token).classes,
                                                    ]"
                                                >
                                                    {{ resolveTokenStatus(token).label }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button variant="outline" size="icon">
                                                            <Ellipsis class="h-8 w-8" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent>
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuSeparator v-if="editTokens" />
                                                        <DropdownMenuGroup v-if="editTokens">
                                                            <DropdownMenuItem
                                                                class="text-blue-500"
                                                                @click.prevent="openEditDialog(token)"
                                                            >
                                                                <Pencil class="mr-2" /> Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                class="text-red-500"
                                                                :disabled="revokeForm.processing || !!token.revoked_at"
                                                                @click.prevent="requestTokenRevocation(token)"
                                                            >
                                                                <Ban class="mr-2" /> Revoke
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="deleteTokens" />
                                                        <DropdownMenuItem
                                                            v-if="deleteTokens"
                                                            class="text-red-500"
                                                            @click.prevent="requestTokenDeletion(token)"
                                                        >
                                                            <Trash2 class="mr-2" /> Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="!filteredTokens.length">
                                            <TableCell colspan="7" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                                No tokens found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                                <div class="text-sm text-muted-foreground text-center md:text-left">
                                    {{ tokensRangeLabel }}
                                </div>
                                <Pagination
                                    v-if="showTokenPagination"
                                    v-slot="{ page, pageCount }"
                                    v-model:page="tokensPage"
                                    :items-per-page="Math.max(tokensMeta.per_page, 1)"
                                    :total="tokensMeta.total"
                                    :sibling-count="1"
                                    show-edges
                                >
                                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                            <PaginationFirst />
                                            <PaginationPrev />

                                            <template v-for="(item, index) in items" :key="index">
                                                <PaginationListItem
                                                    v-if="item.type === 'page'"
                                                    :value="item.value"
                                                    as-child
                                                >
                                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                                        {{ item.value }}
                                                    </Button>
                                                </PaginationListItem>
                                                <PaginationEllipsis
                                                    v-else
                                                    :index="index"
                                                />
                                            </template>

                                            <PaginationNext />
                                            <PaginationLast />
                                        </PaginationList>
                                    </div>
                                </Pagination>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- Token Logs Tab -->
                    <TabsContent value="logs" class="space-y-6">
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                            <div class="flex flex-col gap-4 mb-4">
                                <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                                    <div>
                                        <h2 class="text-lg font-semibold">Token Activity Logs</h2>
                                        <p class="text-sm text-muted-foreground mt-1">
                                            Filter logs by token name, status, or a specific date range.
                                        </p>
                                    </div>
                                    <form
                                        class="grid w-full gap-3 md:w-auto md:grid-cols-2 lg:grid-cols-4"
                                        @submit.prevent="applyLogFilters()"
                                    >
                                        <div class="flex flex-col gap-1">
                                            <Label for="log-token-filter">Token</Label>
                                            <Input
                                                id="log-token-filter"
                                                v-model="logFiltersState.token"
                                                placeholder="Token name"
                                                class="w-full rounded-md"
                                            />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <Label for="log-status-filter">Status</Label>
                                            <select
                                                id="log-status-filter"
                                                v-model="logFiltersState.status"
                                                class="flex h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <option value="">All statuses</option>
                                                <option
                                                    v-for="statusOption in availableLogStatuses"
                                                    :key="statusOption"
                                                    :value="statusOption"
                                                >
                                                    {{ statusOption }}
                                                </option>
                                            </select>
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <Label for="log-date-from">From</Label>
                                            <Input
                                                id="log-date-from"
                                                v-model="logFiltersState.date_from"
                                                type="datetime-local"
                                                class="w-full rounded-md"
                                            />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <Label for="log-date-to">To</Label>
                                            <Input
                                                id="log-date-to"
                                                v-model="logFiltersState.date_to"
                                                type="datetime-local"
                                                class="w-full rounded-md"
                                            />
                                        </div>
                                        <div class="flex items-center justify-end gap-2 md:col-span-2 lg:col-span-4">
                                            <Button type="button" variant="outline" @click="resetLogFilters">
                                                Reset
                                            </Button>
                                            <Button type="submit">
                                                Apply
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <!-- Logs Table -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Token Name</TableHead>
                                            <TableHead>API Route</TableHead>
                                            <TableHead>Timestamp</TableHead>
                                            <TableHead class="text-center">Status</TableHead>
                                            <TableHead class="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="log in tokenLogsItems"
                                            :key="log.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                        >
                                            <TableCell>{{ log.id }}</TableCell>
                                            <TableCell>{{ log.token_name ?? 'Unknown token' }}</TableCell>
                                            <TableCell>{{ log.api_route }}</TableCell>
                                            <TableCell>{{ log.timestamp ? fromNow(log.timestamp) : 'Unknown' }}</TableCell>
                                            <TableCell class="text-center">
                                                <span :class="{
                                                  'text-green-500': log.status === 'success',
                                                  'text-red-500': log.status === 'failed'
                                                }" class="font-medium">
                                                  {{ log.status }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <Link :href="route('acp.tokens.logs.show', { tokenLog: log.id })">
                                                    <Button variant="ghost" class="text-blue-500 text-sm">View</Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="tokenLogsItems.length === 0">
                                            <TableCell colspan="6" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                                No token activity found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                                <div class="text-sm text-muted-foreground text-center md:text-left">
                                    {{ tokenLogsRangeLabel }}
                                </div>
                                <div class="flex flex-col items-center gap-3 md:flex-row md:items-center">
                                    <label class="flex items-center gap-2 text-sm text-muted-foreground">
                                        <span>Per page</span>
                                        <select
                                            class="h-9 rounded-md border border-input bg-transparent px-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                            :value="logsPerPage"
                                            @change="onLogsPerPageChange(Number(($event.target as HTMLSelectElement).value))"
                                        >
                                            <option
                                                v-for="option in LOGS_PER_PAGE_OPTIONS"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}
                                            </option>
                                        </select>
                                    </label>
                                    <Pagination
                                        v-if="showTokenLogsPagination"
                                        v-slot="{ page, pageCount }"
                                        v-model:page="tokenLogsPage"
                                        :items-per-page="Math.max(tokenLogsMeta.per_page, 1)"
                                        :total="tokenLogsMeta.total"
                                        :sibling-count="1"
                                        show-edges
                                    >
                                        <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                            <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                                            <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                                <PaginationFirst />
                                                <PaginationPrev />

                                                <template v-for="(item, index) in items" :key="index">
                                                    <PaginationListItem
                                                        v-if="item.type === 'page'"
                                                        :value="item.value"
                                                        as-child
                                                    >
                                                        <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                                            {{ item.value }}
                                                        </Button>
                                                    </PaginationListItem>
                                                    <PaginationEllipsis
                                                        v-else
                                                        :index="index"
                                                    />
                                                </template>

                                                <PaginationNext />
                                                <PaginationLast />
                                            </PaginationList>
                                        </div>
                                    </Pagination>
                                </div>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AdminLayout>
        <ConfirmDialog
            v-model:open="confirmDialogState.open"
            :title="confirmDialogState.title"
            :description="confirmDialogDescription"
            :confirm-label="confirmDialogState.confirmLabel"
            :cancel-label="confirmDialogState.cancelLabel"
            :confirm-variant="confirmDialogState.confirmVariant"
            :confirm-disabled="confirmDialogState.confirmDisabled"
            @confirm="handleConfirmDialogConfirm"
            @cancel="handleConfirmDialogCancel"
        />
    </AppLayout>
</template>
