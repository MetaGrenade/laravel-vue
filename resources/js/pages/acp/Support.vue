<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell
} from '@/components/ui/table';
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
import {
    XCircle, HelpCircle, Ticket, TicketX, MessageSquare, CheckCircle, Ellipsis, UserPlus, SquareChevronUp,
    Trash2, MoveUp, MoveDown, Pencil, Eye, EyeOff
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';

// dayjs composable for human readable dates
const { fromNow } = useUserTimezone();

// Permission checks
const { hasPermission } = usePermissions();
const createSupport = computed(() => hasPermission('support.acp.create'));
const editSupport = computed(() => hasPermission('support.acp.edit'));
const deleteSupport = computed(() => hasPermission('support.acp.delete'));
const assignSupport = computed(() => hasPermission('support.acp.assign'));
const prioritySupport = computed(() => hasPermission('support.acp.priority'));
const statusSupport = computed(() => hasPermission('support.acp.status'));
const moveSupport = computed(() => hasPermission('support.acp.move'));
const publishSupport = computed(() => hasPermission('support.acp.publish'));

const props = defineProps<{
    tickets: {
        data: Array<{
            id: number;
            subject: string;
            body: string;
            status: 'open' | 'pending' | 'closed';
            priority: 'low' | 'medium' | 'high';
            created_at: string;
            updated_at: string;
            user: {
                id: number;
                name: string;
            };
            assignee: {
                id: number;
                name: string;
            } | null;
        }>;
        current_page: number;
        per_page: number;
        total: number;
    };
    faqs: {
        data: Array<{
            id: number;
            question: string;
            answer: string;
            order: number;
            published: boolean;
        }>;
        current_page: number;
        per_page: number;
        total: number;
    };
    supportStats: {
        total: number;
        open: number;
        closed: number;
        faqs: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Support ACP',
        href: '/acp/support',
    },
];

// Search state
const ticketSearchQuery = ref('');
const faqSearchQuery    = ref('');

// Filtered lists
const filteredTickets = computed(() => {
    const list = props.tickets.data;
    if (!ticketSearchQuery.value) return list;
    const q = ticketSearchQuery.value.toLowerCase();
    return list.filter(t =>
        t.subject.toLowerCase().includes(q) ||
        t.user.name.toLowerCase().includes(q) ||
        t.status.includes(q)
    );
});
const filteredFaqs = computed(() => {
    const list = props.faqs.data;
    if (!faqSearchQuery.value) return list;
    const q = faqSearchQuery.value.toLowerCase();
    return list.filter(f =>
        f.question.toLowerCase().includes(q) ||
        f.answer.toLowerCase().includes(q)
    );
});

console.log(fromNow(new Date()));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Support ACP" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="relative overflow-hidden rounded-xl border p-4 flex items-center">
                        <MessageSquare class="h-8 w-8 mr-3 text-gray-600" />
                        <div>
                            <div class="text-sm text-gray-500">Total Tickets</div>
                            <div class="text-xl font-bold">{{ props.supportStats.total }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border p-4 flex items-center">
                        <XCircle class="h-8 w-8 mr-3 text-gray-600" />
                        <div>
                            <div class="text-sm text-gray-500">Open Tickets</div>
                            <div class="text-xl font-bold">{{ props.supportStats.open }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border p-4 flex items-center">
                        <CheckCircle class="h-8 w-8 mr-3 text-gray-600" />
                        <div>
                            <div class="text-sm text-gray-500">Closed Tickets</div>
                            <div class="text-xl font-bold">{{ props.supportStats.closed }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                    <div class="relative overflow-hidden rounded-xl border p-4 flex items-center">
                        <HelpCircle class="h-8 w-8 mr-3 text-gray-600" />
                        <div>
                            <div class="text-sm text-gray-500">FAQs</div>
                            <div class="text-xl font-bold">{{ props.supportStats.faqs }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Tickets & FAQs Tabs -->
                <Tabs default-value="tickets" class="w-full">
                    <TabsList>
                        <TabsTrigger value="tickets">Support Tickets</TabsTrigger>
                        <TabsTrigger value="faq">FAQs</TabsTrigger>
                    </TabsList>

                    <!-- Tickets Tab -->
                    <TabsContent value="tickets">
                        <div class="rounded-xl border p-4 space-y-4">

                            <!-- Header: Search & Create -->
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                <h2 class="text-lg font-semibold">Ticket Management</h2>
                                <div class="flex space-x-2 w-full md:w-auto">
                                    <Input
                                        v-model="ticketSearchQuery"
                                        placeholder="Search tickets..."
                                        class="flex-1"
                                    />
                                    <Link
                                        v-if="createSupport"
                                        :href="route('acp.support.tickets.create')"
                                    >
                                        <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                            Create Ticket
                                        </Button>
                                    </Link>
                                </div>
                            </div>

                            <!-- Tickets Table -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Subject</TableHead>
                                            <TableHead>Submitted By</TableHead>
                                            <TableHead class="text-center">Status</TableHead>
                                            <TableHead class="text-center">Priority</TableHead>
                                            <TableHead class="text-center">Assigned</TableHead>
                                            <TableHead class="text-center">Created</TableHead>
                                            <TableHead class="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="t in filteredTickets"
                                            :key="t.id"
                                        >
                                            <TableCell>{{ t.id }}</TableCell>
                                            <TableCell>{{ t.subject }}</TableCell>
                                            <TableCell>{{ t.user.name }}</TableCell>
                                            <TableCell class="text-center">
                                                <span :class="{
                                                    'text-blue-500': t.status === 'pending',
                                                    'text-green-500': t.status === 'open',
                                                    'text-red-500': t.status === 'closed'
                                                  }">
                                                    {{ t.status }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <span :class="{
                                                    'text-blue-500': t.priority === 'low',
                                                    'text-yellow-500': t.priority === 'medium',
                                                    'text-red-500': t.priority === 'high'
                                                  }">
                                                    {{ t.priority }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-center">{{ t.assignee?.name || '—' }}</TableCell>
                                            <TableCell class="text-center">{{ fromNow(t.created_at) }}</TableCell>
                                            <TableCell class="text-center">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button variant="outline" size="icon">
                                                            <Ellipsis />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent>
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuSeparator v-if="assignSupport||prioritySupport" />
                                                        <DropdownMenuGroup v-if="assignSupport||prioritySupport">
                                                            <DropdownMenuItem v-if="assignSupport">
                                                                <UserPlus class="h-8 w-8" />
                                                                <span>Add Users</span>
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem v-if="prioritySupport">
                                                                <SquareChevronUp class="h-8 w-8" />
                                                                <span>Elevate Priority</span>
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="editSupport" />
                                                        <DropdownMenuGroup v-if="editSupport">
                                                            <Link :href="route('acp.support.tickets.update', { ticket: t.id })">
                                                                <DropdownMenuItem>
                                                                    <Pencil class="mr-2" /> Edit
                                                                </DropdownMenuItem>
                                                            </Link>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="statusSupport" />
                                                        <DropdownMenuGroup v-if="statusSupport">
                                                            <DropdownMenuItem v-if="t.status !== 'open'" class="text-green-500">
                                                                <Ticket class="mr-2" /> Open Ticket
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem v-if="t.status === 'open'" class="text-red-500">
                                                                <TicketX class="mr-2" /> Close Ticket
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="deleteSupport" />
                                                        <DropdownMenuGroup v-if="deleteSupport">
                                                            <DropdownMenuItem
                                                                @click="$inertia.delete(route('acp.support.tickets.destroy', { ticket: t.id }))"
                                                            >
                                                                <Trash2 class="mr-2" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="!filteredTickets.length">
                                            <TableCell colspan="8" class="text-center text-gray-500">
                                                No tickets found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </TabsContent>

                    <!-- FAQs Tab -->
                    <TabsContent value="faq">
                        <div class="rounded-xl border p-4 space-y-4">

                            <!-- Header: Search & Create -->
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                <h2 class="text-lg font-semibold">FAQ Management</h2>
                                <div class="flex space-x-2 w-full md:w-auto">
                                    <Input
                                        v-model="faqSearchQuery"
                                        placeholder="Search FAQs..."
                                        class="flex-1"
                                    />
                                    <Link
                                        v-if="createSupport"
                                        :href="route('acp.support.faqs.create')"
                                    >
                                        <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                            Create FAQ
                                        </Button>
                                    </Link>
                                </div>
                            </div>

                            <!-- FAQs Table -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Question</TableHead>
                                            <TableHead>Answer</TableHead>
                                            <TableHead>Order</TableHead>
                                            <TableHead>Published</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="f in filteredFaqs"
                                            :key="f.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                        >
                                            <TableCell>{{ f.id }}</TableCell>
                                            <TableCell>{{ f.question }}</TableCell>
                                            <TableCell>{{ f.answer }}</TableCell>
                                            <TableCell>{{ f.order }}</TableCell>
                                            <TableCell>{{ f.published ? 'Yes' : 'No' }}</TableCell>
                                            <TableCell class="text-center">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button variant="outline" size="icon">
                                                            <Ellipsis />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent>
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuSeparator v-if="moveSupport||publishSupport" />
                                                        <DropdownMenuGroup v-if="moveSupport">
                                                            <DropdownMenuItem>
                                                                <MoveUp class="mr-2" /> Move Up
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem>
                                                                <MoveDown class="mr-2" /> Move Down
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuGroup v-if="publishSupport">
                                                            <DropdownMenuItem v-if="!f.published">
                                                                <Eye class="mr-2" /> Publish
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem v-if="f.published">
                                                                <EyeOff class="mr-2" /> Unpublish
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="editSupport||deleteSupport" />
                                                        <DropdownMenuGroup v-if="editSupport||deleteSupport">
                                                            <Link
                                                                v-if="editSupport"
                                                                :href="route('acp.support.faqs.update', { faq: f.id })"
                                                            >
                                                                <DropdownMenuItem>
                                                                    <Pencil class="mr-2" /> Edit
                                                                </DropdownMenuItem>
                                                            </Link>
                                                            <DropdownMenuItem
                                                                v-if="deleteSupport"
                                                                @click="$inertia.delete(route('acp.support.faqs.destroy', { faq: f.id }))"
                                                            >
                                                                <Trash2 class="mr-2" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="!filteredFaqs.length">
                                            <TableCell colspan="6" class="text-center text-gray-500">
                                                No FAQs found.
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
