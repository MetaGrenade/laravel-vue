<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Textarea } from '@/components/ui/textarea';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
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
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Ellipsis, Trash2, Pencil } from 'lucide-vue-next';

// Dummy breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens', href: '/acp/tokens' }
];

// --------------------
// Token Data & Stats
// --------------------
interface Token {
    id: number;
    name: string;
    user: {
        id: number;
        name: string;
        email: string;
    };
    created_at: string;
    last_used_at?: string;
    status: 'active' | 'revoked';
}

const tokens = ref<Token[]>([
    {
        id: 1,
        name: 'Admin Token',
        user: { id: 1, name: 'Admin', email: 'admin@example.com' },
        created_at: '2023-07-25 10:00:00',
        last_used_at: '2023-07-28 08:00:00',
        status: 'active'
    },
    {
        id: 2,
        name: 'Editor Token',
        user: { id: 2, name: 'EditorUser', email: 'editor@example.com' },
        created_at: '2023-07-26 11:00:00',
        last_used_at: '2023-07-28 09:00:00',
        status: 'active'
    },
    {
        id: 3,
        name: 'Old User Token',
        user: { id: 3, name: 'RegularUser', email: 'user@example.com' },
        created_at: '2023-06-15 08:00:00',
        last_used_at: '2023-07-01 08:00:00',
        status: 'revoked'
    },
]);

const totalTokens = computed(() => tokens.value.length);
const activeTokens = computed(() => tokens.value.filter(t => t.status === 'active').length);
const revokedTokens = computed(() => tokens.value.filter(t => t.status === 'revoked').length);

// Search query and filtering for token list
const tokenSearchQuery = ref('');
const filteredTokens = computed(() => {
    if (!tokenSearchQuery.value) return tokens.value;
    const q = tokenSearchQuery.value.toLowerCase();
    return tokens.value.filter(token =>
        token.name.toLowerCase().includes(q) ||
        token.user.name.toLowerCase().includes(q) ||
        token.user.email.toLowerCase().includes(q)
    );
});

// --------------------
// Token Creation Form
// --------------------
const newTokenUserEmail = ref("");
const newTokenName = ref("");
const tokenCreationMessage = ref("");

function createToken() {
    if (!newTokenUserEmail.value.trim() || !newTokenName.value.trim()) {
        tokenCreationMessage.value = "Please fill in all fields.";
        return;
    }
    // In a real implementation, you'd call an API endpoint to generate the token.
    // Here we simulate token creation by adding a new token (without exposing the actual token)
    const newToken: Token = {
        id: Date.now(),
        name: newTokenName.value,
        user: { id: Date.now(), name: newTokenUserEmail.value, email: newTokenUserEmail.value },
        created_at: new Date().toLocaleString(),
        status: 'active'
    };
    tokens.value.push(newToken);
    tokenCreationMessage.value = "Token created successfully. The token value is not displayed for security reasons.";
    newTokenUserEmail.value = "";
    newTokenName.value = "";
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tokens Management" />
        <AdminLayout>
            <div class="container mx-auto p-4 space-y-8">
                <!-- Stats Section -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-1 lg:grid-cols-3">
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
<!--                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />-->
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Total Tokens</div>
                            <div class="text-xl font-bold">{{ totalTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
<!--                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />-->
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Active Tokens</div>
                            <div class="text-xl font-bold">{{ activeTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center">
                        <div class="mr-4">
<!--                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />-->
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Revoked Tokens</div>
                            <div class="text-xl font-bold">{{ revokedTokens }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Tabs for Token List and Token Creation -->
                <Tabs default-value="tokens" class="w-full">
                    <TabsList>
                        <TabsTrigger value="tokens">Token List</TabsTrigger>
                        <TabsTrigger value="create">Create Token</TabsTrigger>
                    </TabsList>

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
                                            <TableHead>Created At</TableHead>
                                            <TableHead>Last Used</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Actions</TableHead>
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
                                                {{ token.user.name }}<br />
                                                <span class="text-xs text-gray-500">{{ token.user.email }}</span>
                                            </TableCell>
                                            <TableCell>{{ token.created_at }}</TableCell>
                                            <TableCell>{{ token.last_used_at || 'Never' }}</TableCell>
                                            <TableCell>
                          <span :class="{
                            'text-green-500': token.status === 'active',
                            'text-red-500': token.status === 'revoked'
                          }" class="font-medium">
                            {{ token.status }}
                          </span>
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button variant="outline" size="icon">
                                                            <Ellipsis class="h-8 w-8" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent>
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuGroup>
                                                            <DropdownMenuItem class="text-blue-500">
                                                                <Pencil class="h-8 w-8" />
                                                                <span>Edit</span>
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem class="text-red-500">
                                                            <Trash2 class="h-8 w-8" />
                                                            <span>Revoke Token</span>
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="filteredTokens.length === 0">
                                            <TableCell colspan="7" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                                No tokens found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- Token Creation Tab -->
                    <TabsContent value="create">
                        <div class="rounded-xl border p-6 shadow">
                            <h2 class="mb-4 text-xl font-bold">Create New Token</h2>
                            <div class="flex flex-col gap-4">
                                <Input
                                    v-model="newTokenUserEmail"
                                    placeholder="User Email"
                                    class="w-full rounded-md"
                                />
                                <Input
                                    v-model="newTokenName"
                                    placeholder="Token Name"
                                    class="w-full rounded-md"
                                />
                                <Button variant="primary" @click="createToken">
                                    Create Token
                                </Button>
                            </div>
                            <div v-if="tokenCreationMessage" class="mt-4 p-4 border rounded bg-gray-100">
                                <p class="font-semibold text-green-600">
                                    {{ tokenCreationMessage }}
                                </p>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
