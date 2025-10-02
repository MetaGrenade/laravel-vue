<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { useDebounceFn } from '@vueuse/core';
import { Toaster, toast } from 'vue-sonner';

// Import shadcn‑vue components
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Ellipsis, TicketX, LifeBuoy, Eye, Paperclip, ChevronDown } from 'lucide-vue-next';
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

interface FAQItem {
    id: number;
    question: string;
    answer: string;
}

interface FAQCategoryFilters {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    order: number;
    published_faqs_count: number;
}

interface FAQGroup {
    category: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        order: number;
    } | null;
    faqs: FAQItem[];
}

interface FAQCategoryOption {
    id: number | null;
    name: string;
    description: string | null;
    count: number;
}

interface FAQPayload {
    groups: FAQGroup[];
    filters: {
        categories: FAQCategoryFilters[];
        selectedCategoryId: number | null;
        search: string | null;
        totalPublished: number;
    };
    matchingCount: number;
}

const props = defineProps<{
    tickets: PaginatedResource<Ticket>;
    faqs: FAQPayload;
    canSubmitTicket: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support', href: '/support' },
];

type FlashMessages = {
    success?: string | null;
    error?: string | null;
    info?: string | null;
};

const page = usePage<SharedData & { flash?: FlashMessages }>();

const flashSuccess = computed(() => page.props.flash?.success ?? '');
const flashError = computed(() => page.props.flash?.error ?? '');
const flashInfo = computed(() => page.props.flash?.info ?? '');

watch(
    flashSuccess,
    (message) => {
        if (message) {
            toast.success(message);
        }
    },
    { immediate: true },
);

watch(
    flashError,
    (message) => {
        if (message) {
            toast.error(message);
        }
    },
    { immediate: true },
);

watch(
    flashInfo,
    (message) => {
        if (message) {
            toast.info(message);
        }
    },
    { immediate: true },
);

const readSearchParam = (location: string, key: string): string => {
    try {
        return new URL(location).searchParams.get(key) ?? '';
    } catch {
        return '';
    }
};

const readNumberParam = (location: string, key: string): number | null => {
    const value = readSearchParam(location, key);

    if (value === '') {
        return null;
    }

    const parsed = Number.parseInt(value, 10);

    return Number.isNaN(parsed) ? null : parsed;
};

const ticketSearchQuery = ref(readSearchParam(page.props.ziggy.location, 'tickets_search'));
const faqFilters = computed(() =>
    props.faqs?.filters ?? {
        categories: [] as FAQCategoryFilters[],
        selectedCategoryId: null,
        search: null,
        totalPublished: 0,
    },
);
const faqSearchQuery = ref(
    faqFilters.value.search ?? readSearchParam(page.props.ziggy.location, 'faqs_search'),
);
const ticketsMetaSource = computed(() => props.tickets.meta ?? null);
const ticketItems = computed(() => props.tickets.data ?? []);
const faqGroups = computed(() => props.faqs.groups ?? []);
const faqCategories = computed(() => faqFilters.value.categories ?? []);
const selectedFaqCategoryId = ref<number | null>(faqFilters.value.selectedCategoryId ?? null);
const totalPublishedFaqs = computed(() => faqFilters.value.totalPublished ?? faqCategories.value.reduce(
    (total, category) => total + category.published_faqs_count,
    0,
));
const faqMatchCount = computed(() => props.faqs.matchingCount ?? faqGroups.value.reduce(
    (total, group) => total + group.faqs.length,
    0,
));
const faqCategoryOptions = computed<FAQCategoryOption[]>(() => {
    const categories = faqCategories.value;
    const total = totalPublishedFaqs.value;

    const options: FAQCategoryOption[] = [
        {
            id: null,
            name: 'All topics',
            description: null,
            count: total,
        },
    ];

    for (const category of categories) {
        options.push({
            id: category.id,
            name: category.name,
            description: category.description,
            count: category.published_faqs_count,
        });
    }

    return options;
});
const activeFaqCategory = computed(() =>
    faqCategoryOptions.value.find((option) => option.id === selectedFaqCategoryId.value) ?? null,
);

interface SupportQueryOverrides {
    tickets_page?: number | null;
    faq_category_id?: number | null;
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

    if (Object.prototype.hasOwnProperty.call(overrides, 'faq_category_id')) {
        const overrideCategory = overrides.faq_category_id;

        if (overrideCategory !== null && overrideCategory !== undefined) {
            query.faq_category_id = overrideCategory;
        }
    } else if (selectedFaqCategoryId.value !== null) {
        query.faq_category_id = selectedFaqCategoryId.value;
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

const showTicketPagination = computed(() => ticketsMeta.value.total > ticketsMeta.value.per_page);

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
const closingTicketId = ref<number | null>(null);

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
    navigateToSupport();
}, 300);

let skipTicketsSearchWatch = false;
let skipFaqsSearchWatch = false;
let skipFaqCategoryWatch = false;

watch(
    () => page.props.ziggy.location,
    (location) => {
        const nextTicketsSearch = readSearchParam(location, 'tickets_search');
        const nextFaqsSearch = readSearchParam(location, 'faqs_search');
        const nextFaqCategory = readNumberParam(location, 'faq_category_id');

        if (ticketSearchQuery.value !== nextTicketsSearch) {
            skipTicketsSearchWatch = true;
            ticketSearchQuery.value = nextTicketsSearch;
        }

        if (faqSearchQuery.value !== nextFaqsSearch) {
            skipFaqsSearchWatch = true;
            faqSearchQuery.value = nextFaqsSearch;
        }

        if (selectedFaqCategoryId.value !== nextFaqCategory) {
            skipFaqCategoryWatch = true;
            selectedFaqCategoryId.value = nextFaqCategory;
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

    debouncedFaqsSearch();
});

watch(selectedFaqCategoryId, () => {
    if (skipFaqCategoryWatch) {
        skipFaqCategoryWatch = false;
        return;
    }

    navigateToSupport({ faq_category_id: selectedFaqCategoryId.value });
});

const goToTicket = (ticketId: number) => {
    router.get(route('support.tickets.show', { ticket: ticketId }));
};

const closeTicket = (ticket: Ticket) => {
    if (ticket.status === 'closed' || closingTicketId.value === ticket.id) {
        return;
    }

    closingTicketId.value = ticket.id;

    router.patch(
        route('support.tickets.status.update', { ticket: ticket.id }),
        { status: 'closed' },
        {
            preserveScroll: true,
            onError: (errors) => {
                const messages = Object.values(errors);
                if (messages.length > 0) {
                    const first = Array.isArray(messages[0]) ? messages[0][0] : messages[0];
                    toast.error(first);
                } else {
                    toast.error('Unable to close the ticket right now.');
                }
            },
            onFinish: () => {
                closingTicketId.value = null;
            },
        },
    );
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

const handleFaqCategorySelect = (categoryId: number | null) => {
    if (selectedFaqCategoryId.value === categoryId) {
        return;
    }

    selectedFaqCategoryId.value = categoryId;
};

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
                                                        <DropdownMenuItem
                                                            class="text-red-500"
                                                            :disabled="
                                                                ticket.status === 'closed' ||
                                                                closingTicketId === ticket.id
                                                            "
                                                            @select="closeTicket(ticket)"
                                                        >
                                                            <TicketX class="h-8 w-8" />
                                                            <span>
                                                                {{
                                                                    closingTicketId === ticket.id
                                                                        ? 'Closing…'
                                                                        : ticket.status === 'closed'
                                                                          ? 'Ticket Closed'
                                                                          : 'Close Ticket'
                                                                }}
                                                            </span>
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
                    <div class="space-y-6">
                        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                            <div class="space-y-1">
                                <h3 class="text-2xl font-semibold">Frequently Asked Questions</h3>
                                <p class="text-sm text-muted-foreground">
                                    Browse curated answers by topic or search for what you need.
                                </p>
                            </div>

                            <div class="flex w-full flex-col gap-3 md:w-auto">
                                <Input
                                    v-model="faqSearchQuery"
                                    placeholder="Search FAQs..."
                                    class="w-full"
                                />
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button
                                        v-for="option in faqCategoryOptions"
                                        :key="option.id ?? 'all'"
                                        type="button"
                                        size="sm"
                                        class="rounded-full"
                                        :variant="option.id === selectedFaqCategoryId ? 'default' : 'outline'"
                                        @click="handleFaqCategorySelect(option.id)"
                                    >
                                        <span>{{ option.name }}</span>
                                        <span class="ml-2 text-xs text-muted-foreground">{{ option.count }}</span>
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="activeFaqCategory?.description"
                            class="rounded-lg border border-dashed border-muted-foreground/30 bg-muted/30 p-4 text-sm text-muted-foreground"
                        >
                            {{ activeFaqCategory.description }}
                        </div>

                        <div class="flex flex-col gap-2 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between">
                            <span>
                                Showing {{ faqMatchCount }} {{ faqMatchCount === 1 ? 'answer' : 'answers' }}
                                <template v-if="faqSearchQuery">
                                    matching “{{ faqSearchQuery }}”
                                </template>
                            </span>
                            <span v-if="selectedFaqCategoryId !== null">
                                Filtered by {{ activeFaqCategory?.name ?? 'selected category' }}
                            </span>
                        </div>

                        <div v-if="faqGroups.length" class="space-y-8">
                            <section
                                v-for="group in faqGroups"
                                :key="group.category?.id ?? 'uncategorized'"
                                class="space-y-4"
                            >
                                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <h4 class="text-xl font-semibold">
                                            {{ group.category?.name ?? 'FAQs' }}
                                        </h4>
                                        <p
                                            v-if="group.category?.description"
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ group.category.description }}
                                        </p>
                                    </div>
                                    <span class="text-sm text-muted-foreground">
                                        {{ group.faqs.length }} {{ group.faqs.length === 1 ? 'article' : 'articles' }}
                                    </span>
                                </div>

                                <div class="space-y-3">
                                    <Collapsible
                                        v-for="(faq, index) in group.faqs"
                                        :key="faq.id"
                                        :default-open="index === 0"
                                    >
                                        <template #default="{ open }">
                                            <div class="overflow-hidden rounded-lg border bg-background shadow-sm">
                                                <CollapsibleTrigger as-child>
                                                    <button
                                                        type="button"
                                                        class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left text-base font-medium"
                                                    >
                                                        <span>{{ faq.question }}</span>
                                                        <ChevronDown
                                                            class="h-4 w-4 flex-shrink-0 transition-transform duration-200"
                                                            :class="open ? 'rotate-180' : ''"
                                                        />
                                                    </button>
                                                </CollapsibleTrigger>
                                                <CollapsibleContent>
                                                    <div class="px-4 pb-4 text-sm leading-relaxed text-muted-foreground whitespace-pre-line">
                                                        {{ faq.answer }}
                                                    </div>
                                                </CollapsibleContent>
                                            </div>
                                        </template>
                                    </Collapsible>
                                </div>
                            </section>
                        </div>

                        <div
                            v-else
                            class="rounded-xl border border-dashed border-muted-foreground/40 p-8 text-center text-sm text-muted-foreground"
                        >
                            No FAQs match your filters. Try adjusting the search or choosing a different category.
                        </div>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
        <Toaster richColors />
    </AppLayout>
</template>
