<script setup lang="ts">
import dayjs from 'dayjs';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
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
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';

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

const props = defineProps<{
    tokens: {
        data: Token[];
        meta?: PaginationMeta | null;
        links?: {
            first: string | null;
            last: string | null;
            prev: string | null;
            next: string | null;
        } | null;
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
    tokenLogs: TokenLog[];
}>();

const {
    meta: tokensMeta,
    page: tokensPage,
    rangeLabel: tokensRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.tokens.meta ?? null),
    itemsLength: computed(() => props.tokens.data?.length ?? 0),
    defaultPerPage: 10,
    itemLabel: 'token',
    itemLabelPlural: 'tokens',
    onNavigate: (page) => {
        router.get(
            route('acp.tokens.index'),
            { page },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

const totalTokens = computed(() => props.tokenStats.total);
const activeTokens = computed(() => props.tokenStats.active);
const expiredTokens = computed(() => props.tokenStats.expired);
const revokedTokens = computed(() => props.tokenStats.revoked);

// Search query and filtering for token list
const tokenSearchQuery = ref('');
const filteredTokens = computed(() => {
    if (!tokenSearchQuery.value) return props.tokens.data;
    const q = tokenSearchQuery.value.toLowerCase();
    return props.tokens.data.filter((token) => {
        const matchesName = token.name.toLowerCase().includes(q);
        const matchesNickname = token.user?.nickname?.toLowerCase().includes(q) ?? false;
        const matchesEmail = token.user?.email?.toLowerCase().includes(q) ?? false;

        return matchesName || matchesNickname || matchesEmail;
    });
});

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

const deleteForm = useForm({});
const deleteToken = (tokenId: number) => {
    deleteForm.delete(route('acp.tokens.destroy', { token: tokenId }), {
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

const lastUsedDisplay = (value?: string | null) => {
    if (!value) {
        return 'Never';
    }

    return fromNow(value);
};

const logSearchQuery = ref('');
const filteredLogs = computed(() => {
    const logs = props.tokenLogs ?? [];

    if (!logSearchQuery.value) {
        return logs;
    }
    const q = logSearchQuery.value.toLowerCase();
    return logs.filter((log) => {
        const tokenName = log.token_name?.toLowerCase() ?? '';
        return (
            tokenName.includes(q) ||
            log.api_route.toLowerCase().includes(q) ||
            log.status.toLowerCase().includes(q) ||
            log.method.toLowerCase().includes(q)
        );
    });
});
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
                                                            <DropdownMenuItem class="text-blue-500">
                                                                <Pencil class="mr-2" /> Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem class="text-red-500">
                                                                <Ban class="mr-2" /> Revoke
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="deleteTokens" />
                                                        <DropdownMenuItem
                                                            v-if="deleteTokens"
                                                            class="text-red-500"
                                                            @click.prevent="deleteToken(token.id)"
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
                                    v-if="tokensMeta.total > 0"
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
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                                <h2 class="text-lg font-semibold mb-2 md:mb-0">Token Activity Logs</h2>
                                <div class="flex space-x-2">
                                    <!-- Search Bar for Logs -->
                                    <Input
                                        v-model="logSearchQuery"
                                        placeholder="Search logs..."
                                        class="w-full rounded-md"
                                    />
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
                                            v-for="log in filteredLogs"
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
                                        <TableRow v-if="filteredLogs.length === 0">
                                            <TableCell colspan="6" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                                No token activity found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
