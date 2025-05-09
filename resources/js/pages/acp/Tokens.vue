<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
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
    DropdownMenuPortal,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Ellipsis, Trash2, Pencil, Coins, ShieldCheck, ShieldAlert, ShieldOff, Ban } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';

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

const props = defineProps<{
    tokens: {
        data: Array<{
            id: number;
            name: string;
            user: {
                id: number;
                nickname: string;
                email: string;
            };
            created_at: string;
            last_used_at?: string;
            status: 'active' | 'expired' | 'revoked';
        }>;
        current_page: number;
        per_page: number;
        total: number;
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
}>();

const totalTokens = computed(() => props.tokenStats.total);
const activeTokens = computed(() => props.tokenStats.active);
const expiredTokens = computed(() => props.tokenStats.expired);
const revokedTokens = computed(() => props.tokenStats.revoked);

// Search query and filtering for token list
const tokenSearchQuery = ref('');
const filteredTokens = computed(() => {
    if (!tokenSearchQuery.value) return props.tokens.data;
    const q = tokenSearchQuery.value.toLowerCase();
    return props.tokens.data.filter((t: any) =>
        t.name.toLowerCase().includes(q) ||
        t.user.nickname.toLowerCase().includes(q) ||
        t.user.email.toLowerCase().includes(q)
    );
});

// --------------------
// Dummy Token Logs Data
// --------------------
interface TokenLog {
    id: number;
    token_name: string;
    api_route: string;
    timestamp: string;
    status: string;
}

const tokenLogs = ref<TokenLog[]>([
    {
        id: 1,
        token_name: 'Admin Token',
        api_route: '/api/dashboard',
        timestamp: '2023-07-28 07:45:00',
        status: 'success',
    },
    {
        id: 2,
        token_name: 'Editor Token',
        api_route: '/api/posts',
        timestamp: '2023-07-28 08:15:00',
        status: 'failed',
    },
    {
        id: 3,
        token_name: 'Discord Bot Token',
        api_route: '/api/discord',
        timestamp: '2025-04-15 08:10:00',
        status: 'success',
    },
]);

const logSearchQuery = ref('');
const filteredLogs = computed(() => {
    if (!logSearchQuery.value) return tokenLogs.value;
    const q = logSearchQuery.value.toLowerCase();
    return tokenLogs.value.filter(log =>
        log.token_name.toLowerCase().includes(q) ||
        log.api_route.toLowerCase().includes(q) ||
        log.status.toLowerCase().includes(q)
    );
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
                            <Button v-if="createTokens" variant="secondary" class="ml-10 text-sm text-white bg-green-500 hover:bg-green-600">
                                Create Token
                            </Button>
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
                                                {{ token.user.nickname }}<br />
                                                <span class="text-xs text-gray-500">{{ token.user.email }}</span>
                                            </TableCell>
                                            <TableCell class="text-center">{{ fromNow(token.created_at) }}</TableCell>
                                            <TableCell class="text-center">{{ fromNow(token.last_used_at) || 'Never' }}</TableCell>
                                            <TableCell class="text-center">
                                                <span :class="{
                                                    'text-green-500': token.status === 'active',
                                                    'text-yellow-500': token.status === 'expired',
                                                    'text-red-500': token.status === 'revoked'
                                                  }" class="font-medium">
                                                  {{ token.status }}
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
                                                        <Link :href="route('acp.tokens.index')" v-if="deleteTokens">
                                                            <DropdownMenuItem  class="text-red-500"
                                                                @click.prevent="$inertia.delete(route('acp.tokens.destroy', { token: t.id }))"
                                                            >
                                                                <Trash2 class="mr-2"/> Delete
                                                            </DropdownMenuItem>
                                                        </Link>
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
                                            <TableCell>{{ log.token_name }}</TableCell>
                                            <TableCell>{{ log.api_route }}</TableCell>
                                            <TableCell>{{ fromNow(log.timestamp) }}</TableCell>
                                            <TableCell class="text-center">
                                                <span :class="{
                                                  'text-green-500': log.status === 'success',
                                                  'text-red-500': log.status === 'failed'
                                                }" class="font-medium">
                                                  {{ log.status }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <Link :href="route('acp.tokens.logs.view')">
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
