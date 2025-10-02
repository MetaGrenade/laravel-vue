<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { useDebounceFn } from '@vueuse/core';

// Import shadcn‑vue components
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
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
import { Ellipsis, TicketX, LifeBuoy, Eye, Paperclip } from 'lucide-vue-next';
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
    updated_at: string | null;
    assignee: TicketAssignee | null;
    customer_satisfaction_rating: number | null;
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

const page = usePage<SharedData>();

const readSearchParam = (location: string, key: string): string => {
    try {
        return new URL(location).searchParams.get(key) ?? '';
    } catch {
        return '';
    }
};

const ticketSearchQuery = ref(readSearchParam(page.props.ziggy.location, 'tickets_search'));
const faqSearchQuery = ref(readSearchParam(page.props.ziggy.location, 'faqs_search'));
const ticketsMetaSource = computed(() => props.tickets.meta ?? null);
const ticketItems = computed(() => props.tickets.data ?? []);
const faqsMetaSource = computed(() => props.faqs.meta ?? null);
const faqItems = computed(() => props.faqs.data ?? []);

interface SupportQueryOverrides {
    tickets_page?: number | null;
    faqs_page?: number | null;
}

const buildSupportQuery = (overrides: SupportQueryOverrides = {}) => {
    const query: Record<string, number | string> = {};

    const ticketsSearch = ticketSearchQuery.value.trim();
    const faqsSearch = faqSearchQuery.value.trim();

    if (ticketsSearch !== '') {
        query.tickets_search = ticketsSearch;
    }

    if (faqsSearch !== '') {
        query.faqs_search = faqsSearch;
    }

    if (overrides.tickets_page !== undefined) {
        if (overrides.tickets_page && overrides.tickets_page > 1) {
            query.tickets_page = overrides.tickets_page;
        }
    } else {
        const currentTicketsPage = ticketsMetaSource.value?.current_page ?? 1;

        if (currentTicketsPage > 1) {
            query.tickets_page = currentTicketsPage;
        }
    }

    if (overrides.faqs_page !== undefined) {
        if (overrides.faqs_page && overrides.faqs_page > 1) {
            query.faqs_page = overrides.faqs_page;
        }
    } else {
        const currentFaqsPage = faqsMetaSource.value?.current_page ?? 1;

        if (currentFaqsPage > 1) {
            query.faqs_page = currentFaqsPage;
        }
    }

    return query;
};

const navigateToSupport = (overrides: SupportQueryOverrides = {}) => {
    router.get(route('support'), buildSupportQuery(overrides), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

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
        navigateToSupport({ tickets_page: page });
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
        navigateToSupport({ faqs_page: page });
    },
});

const showTicketPagination = computed(() => ticketsMeta.value.total > ticketsMeta.value.per_page);
const showFaqPagination = computed(() => faqsMeta.value.total > faqsMeta.value.per_page);

interface CreateTicketFormPayload {
    subject: string;
    body: string;
    priority: 'low' | 'medium' | 'high';
    attachments: File[];
}

const form = useForm<CreateTicketFormPayload>({
    subject: '',
    body: '',
    priority: 'medium',
    attachments: [],
});

const attachmentInput = ref<HTMLInputElement | null>(null);

const handleAttachmentsChange = (event: Event) => {
    const target = event.target as HTMLInputElement;

    form.attachments = target.files ? Array.from(target.files) : [];
};

const resetAttachmentsInput = () => {
    if (attachmentInput.value) {
        attachmentInput.value.value = '';
    }
};

const attachmentErrors = computed(() => {
    const errorEntries = Object.entries(form.errors).filter(([key]) =>
        key === 'attachments' || key.startsWith('attachments.'),
    );

    return errorEntries.length > 0 ? errorEntries[0][1] : '';
});

const formatFileSize = (bytes: number) => {
    if (!bytes) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const size = bytes / Math.pow(1024, exponent);

    const formatted = size >= 10 || exponent === 0 ? size.toFixed(0) : size.toFixed(1);

    return `${formatted} ${units[exponent]}`;
};

const submitTicket = () => {
    form.transform((data) => {
        if (!data.attachments || data.attachments.length === 0) {
            const payload = { ...data };
            delete payload.attachments;

            return payload;
        }

        return data;
    });

    form.post(route('support.tickets.store'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            resetAttachmentsInput();
        },
        onFinish: () => {
            form.transform((data) => ({ ...data }));
        },
    });
};

const debouncedTicketsSearch = useDebounceFn(() => {
    navigateToSupport({ tickets_page: 1 });
}, 300);

const debouncedFaqsSearch = useDebounceFn(() => {
    navigateToSupport({ faqs_page: 1 });
}, 300);

let skipTicketsSearchWatch = false;
let skipFaqsSearchWatch = false;

watch(
    () => page.props.ziggy.location,
    (location) => {
        const nextTicketsSearch = readSearchParam(location, 'tickets_search');
        const nextFaqsSearch = readSearchParam(location, 'faqs_search');

        if (ticketSearchQuery.value !== nextTicketsSearch) {
            skipTicketsSearchWatch = true;
            ticketSearchQuery.value = nextTicketsSearch;
        }

        if (faqSearchQuery.value !== nextFaqsSearch) {
            skipFaqsSearchWatch = true;
            faqSearchQuery.value = nextFaqsSearch;
        }
    },
);

watch(ticketSearchQuery, () => {
    if (skipTicketsSearchWatch) {
        skipTicketsSearchWatch = false;
        return;
    }

    setTicketsPage(1, { emitNavigate: false });
    debouncedTicketsSearch();
});

watch(faqSearchQuery, () => {
    if (skipFaqsSearchWatch) {
        skipFaqsSearchWatch = false;
        return;
    }

    setFaqsPage(1, { emitNavigate: false });
    debouncedFaqsSearch();
});

const goToTicket = (ticketId: number) => {
    router.get(route('support.tickets.show', { ticket: ticketId }));
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

const formatRating = (rating: number | null) =>
    typeof rating === 'number' ? `${rating}/5` : '—';

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
                                        <TableHead class="text-center">Rating</TableHead>
                                        <TableHead class="text-center">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow
                                        v-for="ticket in ticketItems"
                                        :key="ticket.id"
                                        class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-900"
                                        @click="goToTicket(ticket.id)"
                                    >
                                        <TableCell>
                                            <Link
                                                :href="route('support.tickets.show', { ticket: ticket.id })"
                                                class="font-medium text-primary hover:underline"
                                                @click.stop
                                            >
                                                #{{ ticket.id }}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                :href="route('support.tickets.show', { ticket: ticket.id })"
                                                class="font-medium text-primary hover:underline"
                                                @click.stop
                                            >
                                                {{ ticket.subject }}
                                            </Link>
                                        </TableCell>
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
                                        <TableCell class="text-center">{{ formatRating(ticket.customer_satisfaction_rating) }}</TableCell>
                                        <TableCell class="text-center">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child @click.stop>
                                                    <Button variant="outline" size="icon">
                                                        <Ellipsis class="h-8 w-8" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent>
                                                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuGroup>
                                                        <DropdownMenuItem @select="goToTicket(ticket.id)">
                                                            <Eye class="h-8 w-8" />
                                                            <span>View Ticket</span>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem class="text-red-500">
                                                            <TicketX class="h-8 w-8" />
                                                            <span>Close Ticket</span>
                                                        </DropdownMenuItem>
                                                    </DropdownMenuGroup>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-if="ticketItems.length === 0">
                                        <TableCell colspan="8" class="text-center text-sm text-gray-600 dark:text-gray-300">
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
                                    <Label for="ticket-subject">Subject</Label>
                                    <Input
                                        id="ticket-subject"
                                        v-model="form.subject"
                                        placeholder="Ticket subject"
                                        class="w-full rounded-md"
                                        autocomplete="off"
                                        required
                                    />
                                    <InputError :message="form.errors.subject" />
                                </div>
                                <div class="space-y-2">
                                    <Label for="ticket-priority">Priority</Label>
                                    <select
                                        id="ticket-priority"
                                        v-model="form.priority"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700"
                                        :disabled="form.processing"
                                        required
                                    >
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                    <p class="text-xs text-muted-foreground">
                                        Higher priority tickets jump the queue for triage, while lower priority requests may
                                        receive responses during standard support hours.
                                    </p>
                                    <InputError :message="form.errors.priority" />
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
                                <div class="flex flex-col gap-2">
                                    <Input
                                        ref="attachmentInput"
                                        id="attachments"
                                        type="file"
                                        multiple
                                        :disabled="form.processing"
                                        accept="image/*,application/pdf,text/plain,text/csv,application/zip,application/json,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/x-ndjson"
                                        @change="handleAttachmentsChange"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Attach relevant screenshots or log files (up to 5 files, 10&nbsp;MB each).
                                    </p>
                                    <ul v-if="form.attachments.length" class="flex flex-wrap gap-2 text-xs">
                                        <li
                                            v-for="file in form.attachments"
                                            :key="`${file.name}-${file.lastModified}`"
                                            class="flex items-center gap-2 rounded-md border border-dashed border-muted bg-muted/40 px-2 py-1"
                                        >
                                            <Paperclip class="h-3 w-3" />
                                            <span class="max-w-[10rem] truncate">{{ file.name }}</span>
                                            <span class="text-muted-foreground">{{ formatFileSize(file.size) }}</span>
                                        </li>
                                    </ul>
                                    <InputError :message="attachmentErrors" />
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
                                    v-for="faq in faqItems"
                                    :key="faq.id"
                                    class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                >
                                    <TableCell>{{ faq.question }}</TableCell>
                                    <TableCell>{{ faq.answer }}</TableCell>
                                </TableRow>
                                <TableRow v-if="faqItems.length === 0">
                                    <TableCell
                                        colspan="2"
                                        class="text-center text-sm text-gray-600 dark:text-gray-300"
                                    >
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
