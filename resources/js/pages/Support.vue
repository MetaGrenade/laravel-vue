<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';

// Import shadcn‑vue components
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Textarea } from '@/components/ui/textarea';
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
import { Ellipsis, TicketX, LifeBuoy } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
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

interface TicketAssignee {
    id: number;
    nickname: string;
    email: string;
}

interface Ticket {
    id: number;
    subject: string;
    status: 'open' | 'pending' | 'closed';
    priority: 'low' | 'medium' | 'high';
    created_at: string | null;
    assignee: TicketAssignee | null;
}

interface FAQ {
    id: number;
    question: string;
    answer: string;
}

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

interface PaginatedResource<T> {
    data: T[];
    meta?: PaginationMeta | null;
    links?: PaginationLinks | null;
}

const props = defineProps<{
    tickets: PaginatedResource<Ticket>;
    faqs: PaginatedResource<FAQ>;
    canSubmitTicket: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support', href: '/support' },
];

const ticketSearchQuery = ref('');
const ticketsMetaSource = computed(() => props.tickets.meta ?? null);
const ticketItems = computed(() => props.tickets.data ?? []);
const filteredTickets = computed(() => {
    if (!props.canSubmitTicket) {
        return [];
    }

    if (!ticketSearchQuery.value) {
        return ticketItems.value;
    }

    const q = ticketSearchQuery.value.toLowerCase();

    return ticketItems.value.filter(ticket => {
        return [
            ticket.subject,
            ticket.status,
            ticket.priority,
            ticket.assignee?.nickname ?? '',
        ]
            .join(' ')
            .toLowerCase()
            .includes(q);
    });
});

const faqSearchQuery = ref('');
const faqsMetaSource = computed(() => props.faqs.meta ?? null);
const faqItems = computed(() => props.faqs.data ?? []);
const filteredFaqs = computed(() => {
    if (!faqSearchQuery.value) {
        return faqItems.value;
    }

    const q = faqSearchQuery.value.toLowerCase();

    return faqItems.value.filter(faq =>
        faq.question.toLowerCase().includes(q) || faq.answer.toLowerCase().includes(q),
    );
});

const {
    meta: ticketsMeta,
    page: ticketsPage,
    setPage: setTicketsPage,
    rangeLabel: ticketsRangeLabel,
} = useInertiaPagination({
    meta: ticketsMetaSource,
    itemsLength: computed(() => ticketItems.value.length),
    defaultPerPage: 10,
    itemLabel: 'ticket',
    itemLabelPlural: 'tickets',
    emptyLabel: 'No tickets to display',
    onNavigate: (page) => {
        router.get(
            route('support'),
            {
                tickets_page: page,
                faqs_page:
                    faqsMetaSource.value?.current_page && faqsMetaSource.value.current_page > 1
                        ? faqsMetaSource.value.current_page
                        : undefined,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

const {
    meta: faqsMeta,
    page: faqsPage,
    setPage: setFaqsPage,
    rangeLabel: faqsRangeLabel,
} = useInertiaPagination({
    meta: faqsMetaSource,
    itemsLength: computed(() => faqItems.value.length),
    defaultPerPage: 10,
    itemLabel: 'FAQ',
    itemLabelPlural: 'FAQs',
    emptyLabel: "No FAQs to display",
    onNavigate: (page) => {
        router.get(
            route('support'),
            {
                faqs_page: page,
                tickets_page:
                    ticketsMetaSource.value?.current_page && ticketsMetaSource.value.current_page > 1
                        ? ticketsMetaSource.value.current_page
                        : undefined,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

const showTicketPagination = computed(() => ticketsMeta.value.total > ticketsMeta.value.per_page);
const showFaqPagination = computed(() => faqsMeta.value.total > faqsMeta.value.per_page);

const form = useForm({
    subject: '',
    body: '',
});

const submitTicket = () => {
    form.post(route('support.tickets.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

const statusClass = (status: Ticket['status']) => {
    const classes: Record<Ticket['status'], string> = {
        pending: 'text-blue-500',
        open: 'text-green-500',
        closed: 'text-red-500',
    };

    return classes[status] ?? '';
};

const priorityClass = (priority: Ticket['priority']) => {
    const classes: Record<Ticket['priority'], string> = {
        low: 'text-blue-500',
        medium: 'text-yellow-500',
        high: 'text-red-500',
    };

    return classes[priority] ?? '';
};

const formatStatus = (status: Ticket['status']) =>
    status.charAt(0).toUpperCase() + status.slice(1);

const formatPriority = (priority: Ticket['priority']) =>
    priority.charAt(0).toUpperCase() + priority.slice(1);

const formatDate = (value: string | null) => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
};

watch(ticketSearchQuery, () => {
    setTicketsPage(1, { emitNavigate: false });
});

watch(faqSearchQuery, () => {
    setFaqsPage(1, { emitNavigate: false });
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Support Center" />
        <div class="container mx-auto p-4 space-y-8">
            <h1 class="text-3xl font-bold mb-4">
                <LifeBuoy class="h-8 w-8 text-green-600 inline-block" />
                Support Center
            </h1>

            <Tabs default-value="tickets" class="w-full">
                <TabsList class="mb-4">
                    <TabsTrigger value="tickets">Support Tickets</TabsTrigger>
                    <TabsTrigger value="faq">Frequently Asked Questions</TabsTrigger>
                </TabsList>

                <!-- My Tickets Tab -->
                <TabsContent value="tickets" class="space-y-6">
                    <!-- Search and New Ticket Button -->
                    <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                        <h3 class="text-2xl font-semibold mb-4">My Tickets</h3>
                        <Input
                            v-model="ticketSearchQuery"
                            placeholder="Search your tickets..."
                            class="w-full md:w-1/3 md:ml-auto"
                            :disabled="!props.canSubmitTicket"
                        />
                        <Button
                            v-if="props.canSubmitTicket"
                            variant="secondary"
                            class="md:ml-auto cursor-pointer"
                            as-child
                        >
                            <a href="#create_ticket">Create New Ticket</a>
                        </Button>
                        <Button
                            v-else
                            variant="secondary"
                            class="md:ml-auto cursor-pointer"
                            as-child
                        >
                            <Link :href="route('login')">Sign in to submit</Link>
                        </Button>
                    </div>

                    <template v-if="props.canSubmitTicket">
                        <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                            <div class="text-sm text-muted-foreground text-center md:text-left">
                                {{ ticketsRangeLabel }}
                            </div>
                            <Pagination
                                v-if="showTicketPagination"
                                v-slot="{ page, pageCount }"
                                v-model:page="ticketsPage"
                                :items-per-page="Math.max(ticketsMeta.per_page, 1)"
                                :total="ticketsMeta.total"
                                :sibling-count="1"
                                show-edges
                            >
                                <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                    <span class="text-sm text-muted-foreground">
                                        Page {{ page }} of {{ pageCount }}
                                    </span>
                                    <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                        <PaginationFirst />
                                        <PaginationPrev />

                                        <template v-for="(item, index) in items" :key="index">
                                            <PaginationListItem
                                                v-if="item.type === 'page'"
                                                :value="item.value"
                                                as-child
                                            >
                                                <Button
                                                    class="w-9 h-9 p-0"
                                                    :variant="item.value === page ? 'default' : 'outline'"
                                                >
                                                    {{ item.value }}
                                                </Button>
                                            </PaginationListItem>
                                            <PaginationEllipsis v-else :key="`tickets-top-ellipsis-${index}`" :index="index" />
                                        </template>

                                        <PaginationNext />
                                        <PaginationLast />
                                    </PaginationList>
                                </div>
                            </Pagination>
                        </div>
                        <!-- Tickets Table -->
                        <div class="overflow-x-auto rounded-xl border p-4 shadow-sm">
                            <Table>
                                <TableHeader class="bg-neutral-900">
                                    <TableRow>
                                        <TableHead>ID</TableHead>
                                        <TableHead>Subject</TableHead>
                                        <TableHead class="text-center">Status</TableHead>
                                        <TableHead class="text-center">Priority</TableHead>
                                        <TableHead class="text-center">Assigned</TableHead>
                                        <TableHead class="text-center">Created At</TableHead>
                                        <TableHead class="text-center">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow
                                        v-for="ticket in filteredTickets"
                                        :key="ticket.id"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                    >
                                        <TableCell>{{ ticket.id }}</TableCell>
                                        <TableCell>{{ ticket.subject }}</TableCell>
                                        <TableCell class="text-center">
                                            <span :class="statusClass(ticket.status)">
                                                {{ formatStatus(ticket.status) }}
                                            </span>
                                        </TableCell>
                                        <TableCell class="text-center">
                                            <span :class="priorityClass(ticket.priority)">
                                                {{ formatPriority(ticket.priority) }}
                                            </span>
                                        </TableCell>
                                        <TableCell class="text-center">{{ ticket.assignee?.nickname || '—' }}</TableCell>
                                        <TableCell class="text-center">{{ formatDate(ticket.created_at) }}</TableCell>
                                        <TableCell class="text-center">
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
                                                        <DropdownMenuItem class="text-red-500">
                                                            <TicketX class="h-8 w-8" />
                                                            <span>Close Ticket</span>
                                                        </DropdownMenuItem>
                                                    </DropdownMenuGroup>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-if="filteredTickets.length === 0">
                                        <TableCell colspan="7" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                            No tickets found.
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <div
                            v-if="showTicketPagination"
                            class="flex flex-col items-center justify-between gap-4 md:flex-row"
                        >
                            <div class="text-sm text-muted-foreground text-center md:text-left">
                                {{ ticketsRangeLabel }}
                            </div>
                            <Pagination
                                v-slot="{ page, pageCount }"
                                v-model:page="ticketsPage"
                                :items-per-page="Math.max(ticketsMeta.per_page, 1)"
                                :total="ticketsMeta.total"
                                :sibling-count="1"
                                show-edges
                            >
                                <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                    <span class="text-sm text-muted-foreground">
                                        Page {{ page }} of {{ pageCount }}
                                    </span>
                                    <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                        <PaginationFirst />
                                        <PaginationPrev />

                                        <template v-for="(item, index) in items" :key="index">
                                            <PaginationListItem
                                                v-if="item.type === 'page'"
                                                :value="item.value"
                                                as-child
                                            >
                                                <Button
                                                    class="w-9 h-9 p-0"
                                                    :variant="item.value === page ? 'default' : 'outline'"
                                                >
                                                    {{ item.value }}
                                                </Button>
                                            </PaginationListItem>
                                            <PaginationEllipsis v-else :key="`tickets-bottom-ellipsis-${index}`" :index="index" />
                                        </template>

                                        <PaginationNext />
                                        <PaginationLast />
                                    </PaginationList>
                                </div>
                            </Pagination>
                        </div>

                        <!-- New Ticket Submission Form -->
                        <div class="rounded-xl border p-6 shadow">
                            <h2 class="mb-4 text-xl font-bold" id="create_ticket">Create New Ticket</h2>
                            <form class="flex flex-col gap-4" @submit.prevent="submitTicket">
                                <div class="space-y-2">
                                    <Input
                                        v-model="form.subject"
                                        placeholder="Ticket subject"
                                        class="w-full rounded-md"
                                        autocomplete="off"
                                        required
                                    />
                                    <InputError :message="form.errors.subject" />
                                </div>
                                <div class="space-y-2">
                                    <Textarea
                                        v-model="form.body"
                                        placeholder="Describe your issue..."
                                        class="w-full rounded-md"
                                        required
                                    />
                                    <InputError :message="form.errors.body" />
                                </div>
                                <div class="flex justify-end">
                                    <Button
                                        type="submit"
                                        class="bg-green-500 hover:bg-green-600"
                                        :disabled="form.processing"
                                    >
                                        Submit Ticket
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </template>
                    <template v-else>
                        <div class="rounded-xl border p-6 shadow space-y-4 text-center">
                            <p class="text-lg font-semibold">Need personalised help?</p>
                            <p class="text-sm text-muted-foreground">
                                Sign in to create support requests and review your ticket history.
                            </p>
                            <div class="flex justify-center">
                                <Button as-child>
                                    <Link :href="route('login')">Sign in</Link>
                                </Button>
                            </div>
                        </div>
                    </template>
                </TabsContent>

                <!-- FAQ Tab -->
                <TabsContent value="faq" class="space-y-6">
                    <!-- Search FAQ -->
                    <div class="flex">
                        <h3 class="text-2xl font-semibold mb-4">FAQ's</h3>
                        <Input
                            v-model="faqSearchQuery"
                            placeholder="Search FAQs..."
                            class="w-full md:w-1/3 md:ml-auto"
                        />
                    </div>

                    <!-- FAQ List -->
                    <div class="overflow-x-auto rounded-xl border">
                        <Table>
                            <TableHeader class="bg-neutral-900">
                                <TableRow>
                                    <TableHead>Question</TableHead>
                                    <TableHead>Answer</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="faq in filteredFaqs"
                                    :key="faq.id"
                                    class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                >
                                    <TableCell>{{ faq.question }}</TableCell>
                                    <TableCell>{{ faq.answer }}</TableCell>
                                </TableRow>
                                  <TableRow v-if="filteredFaqs.length === 0">
                                      <TableCell colspan="2" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                          No FAQs found.
                                      </TableCell>
                                  </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                        <div class="text-sm text-muted-foreground text-center md:text-left">
                            {{ faqsRangeLabel }}
                        </div>
                        <Pagination
                            v-if="showFaqPagination"
                            v-slot="{ page, pageCount }"
                            v-model:page="faqsPage"
                            :items-per-page="Math.max(faqsMeta.per_page, 1)"
                            :total="faqsMeta.total"
                            :sibling-count="1"
                            show-edges
                        >
                            <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                <span class="text-sm text-muted-foreground">
                                    Page {{ page }} of {{ pageCount }}
                                </span>
                                <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                    <PaginationFirst />
                                    <PaginationPrev />

                                    <template v-for="(item, index) in items" :key="index">
                                        <PaginationListItem
                                            v-if="item.type === 'page'"
                                            :value="item.value"
                                            as-child
                                        >
                                            <Button
                                                class="w-9 h-9 p-0"
                                                :variant="item.value === page ? 'default' : 'outline'"
                                            >
                                                {{ item.value }}
                                            </Button>
                                        </PaginationListItem>
                                        <PaginationEllipsis v-else :key="`faqs-ellipsis-${index}`" :index="index" />
                                    </template>

                                    <PaginationNext />
                                    <PaginationLast />
                                </PaginationList>
                            </div>
                        </Pagination>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
