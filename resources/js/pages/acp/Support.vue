<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Toaster, toast } from 'vue-sonner';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import Button from '@/components/ui/button/Button.vue';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    XCircle, HelpCircle, Ticket, TicketX, MessageSquare, CheckCircle, Ellipsis, UserPlus, SquareChevronUp,
    Trash2, MoveUp, MoveDown, Pencil, Eye, EyeOff, X
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import Input from '@/components/ui/input/Input.vue';
import { useDebounceFn } from '@vueuse/core';

// dayjs composable for human readable dates
const { fromNow, formatDate } = useUserTimezone();

// Permission checks
const { hasPermission } = usePermissions();
const viewSupport = computed(() => hasPermission('support.acp.view'));
const createSupport = computed(() => hasPermission('support.acp.create'));
const editSupport = computed(() => hasPermission('support.acp.edit'));
const deleteSupport = computed(() => hasPermission('support.acp.delete'));
const assignSupport = computed(() => hasPermission('support.acp.assign'));
const prioritySupport = computed(() => hasPermission('support.acp.priority'));
const statusSupport = computed(() => hasPermission('support.acp.status'));
const moveSupport = computed(() => hasPermission('support.acp.move'));
const publishSupport = computed(() => hasPermission('support.acp.publish'));

type PaginationLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

type TicketStatus = 'open' | 'pending' | 'closed';
type TicketPriority = 'low' | 'medium' | 'high';

const props = defineProps<{
    tickets: {
        data: Array<{
            id: number;
            subject: string;
            body: string;
            status: TicketStatus;
            priority: TicketPriority;
            created_at: string | null;
            updated_at: string | null;
            resolved_at: string | null;
            resolved_by: number | null;
            customer_satisfaction_rating: number | null;
            user: {
                id: number;
                nickname: string;
                email: string;
            } | null;
            assignee: {
                id: number;
                nickname: string;
                email?: string;
            } | null;
            resolver: {
                id: number;
                nickname: string;
                email?: string;
            } | null;
            category: {
                id: number;
                name: string;
            } | null;
        }>;
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    faqs: {
        data: Array<{
            id: number;
            question: string;
            answer: string;
            order: number;
            published: boolean;
            category: {
                id: number;
                name: string;
                slug: string;
            } | null;
        }>;
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    supportStats: {
        total: number;
        open: number;
        closed: number;
        faqs: number;
    };
    assignableAgents: Array<{
        id: number;
        nickname: string;
        email: string;
    }>;
    ticketFilters: {
        status: TicketStatus | null;
        priority: TicketPriority | null;
        assignee: number | 'unassigned' | null;
        date_from: string | null;
        date_to: string | null;
    };
}>();

type Ticket = (typeof props.tickets.data)[number];
type FaqItem = (typeof props.faqs.data)[number];

const quickActionVisitOptions = {
    preserveScroll: true,
    preserveState: true,
    replace: true,
} as const;

const assignDialogOpen = ref(false);
const assignDialogTicket = ref<Ticket | null>(null);
const assignForm = useForm<{ assigned_to: number | null }>({
    assigned_to: null,
});

const handleAssignDialogChange = (open: boolean) => {
    assignDialogOpen.value = open;

    if (!open) {
        assignDialogTicket.value = null;
        assignForm.reset();
        assignForm.clearErrors();
    }
};

const openAssignDialog = (ticket: Ticket) => {
    assignDialogTicket.value = ticket;
    assignForm.reset();
    assignForm.clearErrors();
    assignForm.assigned_to = ticket.assignee?.id ?? null;
    handleAssignDialogChange(true);
};

const submitAssignForm = () => {
    if (!assignDialogTicket.value) {
        return;
    }

    assignForm.put(route('acp.support.tickets.assign', { ticket: assignDialogTicket.value.id }), {
        ...quickActionVisitOptions,
        onSuccess: () => handleAssignDialogChange(false),
    });
};

const priorityDialogOpen = ref(false);
const priorityDialogTicket = ref<Ticket | null>(null);
const priorityDialogNextPriority = ref<Ticket['priority'] | null>(null);
const priorityLevels: Ticket['priority'][] = ['low', 'medium', 'high'];
const formatPriority = (priority: Ticket['priority']) =>
    `${priority.charAt(0).toUpperCase()}${priority.slice(1)}`;

const formatStatus = (status: Ticket['status']) =>
    `${status.charAt(0).toUpperCase()}${status.slice(1)}`;

const handlePriorityDialogChange = (open: boolean) => {
    priorityDialogOpen.value = open;

    if (!open) {
        priorityDialogTicket.value = null;
        priorityDialogNextPriority.value = null;
    }
};

const openPriorityDialog = (ticket: Ticket) => {
    priorityDialogTicket.value = ticket;
    priorityDialogNextPriority.value = ticket.priority;
    handlePriorityDialogChange(true);
};

const confirmPriorityUpdate = () => {
    if (!priorityDialogTicket.value || !priorityDialogNextPriority.value) {
        return;
    }

    if (priorityDialogNextPriority.value === priorityDialogTicket.value.priority) {
        handlePriorityDialogChange(false);
        return;
    }

    router.put(
        route('acp.support.tickets.priority', { ticket: priorityDialogTicket.value.id }),
        { priority: priorityDialogNextPriority.value },
        {
            ...quickActionVisitOptions,
            onSuccess: () => handlePriorityDialogChange(false),
        },
    );
};

const statusDialogOpen = ref(false);
const statusDialogTicket = ref<Ticket | null>(null);
const statusDialogStatus = ref<Ticket['status'] | null>(null);

const handleStatusDialogChange = (open: boolean) => {
    statusDialogOpen.value = open;

    if (!open) {
        statusDialogTicket.value = null;
        statusDialogStatus.value = null;
    }
};

const openStatusDialog = (ticket: Ticket, status: Ticket['status']) => {
    statusDialogTicket.value = ticket;
    statusDialogStatus.value = status;
    handleStatusDialogChange(true);
};

const statusDialogActionLabel = computed(() => {
    if (!statusDialogStatus.value) {
        return '';
    }

    if (statusDialogStatus.value === 'open') {
        return 'open this ticket';
    }

    if (statusDialogStatus.value === 'closed') {
        return 'close this ticket';
    }

    if (statusDialogStatus.value === 'pending') {
        return 'mark this ticket as pending';
    }

    return `mark this ticket as ${statusDialogStatus.value}`;
});

const confirmStatusUpdate = () => {
    if (!statusDialogTicket.value || !statusDialogStatus.value) {
        return;
    }

    router.put(
        route('acp.support.tickets.status', { ticket: statusDialogTicket.value.id }),
        { status: statusDialogStatus.value },
        {
            ...quickActionVisitOptions,
            onSuccess: () => handleStatusDialogChange(false),
        },
    );
};

const page = usePage<SharedData>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Support ACP',
        href: '/acp/support',
    },
];

const readSearchParam = (location: string, key: string): string => {
    try {
        return new URL(location).searchParams.get(key) ?? '';
    } catch {
        return '';
    }
};

const ticketSearchQuery = ref(readSearchParam(page.props.ziggy.location, 'tickets_search'));
const faqSearchQuery = ref(readSearchParam(page.props.ziggy.location, 'faqs_search'));

const initialStatusFromQuery = readSearchParam(page.props.ziggy.location, 'status');
const initialPriorityFromQuery = readSearchParam(page.props.ziggy.location, 'priority');
const initialAssigneeFromQuery = readSearchParam(page.props.ziggy.location, 'assignee');
const initialDateFromQuery = readSearchParam(page.props.ziggy.location, 'date_from');
const initialDateToQuery = readSearchParam(page.props.ziggy.location, 'date_to');

const statusFilter = ref(
    initialStatusFromQuery !== ''
        ? initialStatusFromQuery
        : props.ticketFilters.status ?? ''
);
const priorityFilter = ref(
    initialPriorityFromQuery !== ''
        ? initialPriorityFromQuery
        : props.ticketFilters.priority ?? ''
);
const assigneeFilter = ref(
    initialAssigneeFromQuery !== ''
        ? initialAssigneeFromQuery
        : props.ticketFilters.assignee === null
            ? ''
            : String(props.ticketFilters.assignee)
);
const dateFromFilter = ref(
    initialDateFromQuery !== ''
        ? initialDateFromQuery
        : props.ticketFilters.date_from ?? ''
);
const dateToFilter = ref(
    initialDateToQuery !== ''
        ? initialDateToQuery
        : props.ticketFilters.date_to ?? ''
);

const ticketStatusOptions: Array<{ value: '' | TicketStatus; label: string }> = [
    { value: '', label: 'All statuses' },
    { value: 'open', label: 'Open' },
    { value: 'pending', label: 'Pending' },
    { value: 'closed', label: 'Closed' },
];

const ticketPriorityOptions: Array<{ value: '' | TicketPriority; label: string }> = [
    { value: '', label: 'All priorities' },
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const assigneeOptions = computed(() => {
    const options: Array<{ value: string; label: string }> = [
        { value: '', label: 'All assignees' },
        { value: 'unassigned', label: 'Unassigned' },
    ];

    props.assignableAgents.forEach((agent) => {
        options.push({ value: String(agent.id), label: agent.nickname });
    });

    return options;
});

const selectFilterClass =
    'h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2';

const ticketsMetaSource = computed(() => props.tickets.meta ?? null);
const faqsMetaSource = computed(() => props.faqs.meta ?? null);
const ticketItems = computed(() => props.tickets.data ?? []);
const faqItems = computed(() => props.faqs.data ?? []);

type TicketFilterChipKey = 'status' | 'priority' | 'assignee' | 'date_range';

interface TicketFilterChip {
    key: TicketFilterChipKey;
    label: string;
}

const activeTicketFilterChips = computed<TicketFilterChip[]>(() => {
    const chips: TicketFilterChip[] = [];

    if (statusFilter.value !== '') {
        chips.push({
            key: 'status',
            label: `Status: ${formatStatus(statusFilter.value as TicketStatus)}`,
        });
    }

    if (priorityFilter.value !== '') {
        chips.push({
            key: 'priority',
            label: `Priority: ${formatPriority(priorityFilter.value as TicketPriority)}`,
        });
    }

    if (assigneeFilter.value !== '') {
        let label = 'Assignee: Unassigned';

        if (assigneeFilter.value !== 'unassigned') {
            const matchedAgent = props.assignableAgents.find((agent) => String(agent.id) === assigneeFilter.value);
            label = matchedAgent ? `Assignee: ${matchedAgent.nickname}` : `Assignee: #${assigneeFilter.value}`;
        }

        chips.push({
            key: 'assignee',
            label,
        });
    }

    if (dateFromFilter.value !== '' || dateToFilter.value !== '') {
        let label = 'Created';

        if (dateFromFilter.value !== '' && dateToFilter.value !== '') {
            label = `Created: ${dateFromFilter.value} → ${dateToFilter.value}`;
        } else if (dateFromFilter.value !== '') {
            label = `Created: ≥ ${dateFromFilter.value}`;
        } else if (dateToFilter.value !== '') {
            label = `Created: ≤ ${dateToFilter.value}`;
        }

        chips.push({
            key: 'date_range',
            label,
        });
    }

    return chips;
});

const hasTicketFilters = computed(() => activeTicketFilterChips.value.length > 0);

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

    if (statusFilter.value !== '') {
        query.status = statusFilter.value;
    }

    if (priorityFilter.value !== '') {
        query.priority = priorityFilter.value;
    }

    if (assigneeFilter.value !== '') {
        query.assignee = assigneeFilter.value;
    }

    if (dateFromFilter.value !== '') {
        query.date_from = dateFromFilter.value;
    }

    if (dateToFilter.value !== '') {
        query.date_to = dateToFilter.value;
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
    router.get(route('acp.support.index'), buildSupportQuery(overrides), {
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
    defaultPerPage: 25,
    itemLabel: 'support ticket',
    itemLabelPlural: 'support tickets',
    onNavigate: (page) => {
        navigateToSupport({ tickets_page: page });
    },
});

const navigateTicketsWithFilters = () => {
    setTicketsPage(1, { emitNavigate: false });
    navigateToSupport({ tickets_page: 1 });
};

const {
    meta: faqsMeta,
    page: faqsPage,
    setPage: setFaqsPage,
    rangeLabel: faqsRangeLabel,
} = useInertiaPagination({
    meta: faqsMetaSource,
    itemsLength: computed(() => faqItems.value.length),
    defaultPerPage: 25,
    itemLabel: 'FAQ',
    itemLabelPlural: 'FAQs',
    onNavigate: (page) => {
        navigateToSupport({ faqs_page: page });
    },
});

const debouncedTicketsSearch = useDebounceFn(() => {
    navigateToSupport({ tickets_page: 1 });
}, 300);

const debouncedFaqsSearch = useDebounceFn(() => {
    navigateToSupport({ faqs_page: 1 });
}, 300);

let skipTicketsSearchWatch = false;
let skipFaqsSearchWatch = false;
let skipStatusFilterWatch = false;
let skipPriorityFilterWatch = false;
let skipAssigneeFilterWatch = false;
let skipDateFromFilterWatch = false;
let skipDateToFilterWatch = false;

watch(
    () => page.props.ziggy.location,
    (location) => {
        const nextTicketsSearch = readSearchParam(location, 'tickets_search');
        const nextFaqsSearch = readSearchParam(location, 'faqs_search');
        const nextStatus = readSearchParam(location, 'status');
        const nextPriority = readSearchParam(location, 'priority');
        const nextAssignee = readSearchParam(location, 'assignee');
        const nextDateFrom = readSearchParam(location, 'date_from');
        const nextDateTo = readSearchParam(location, 'date_to');

        if (ticketSearchQuery.value !== nextTicketsSearch) {
            skipTicketsSearchWatch = true;
            ticketSearchQuery.value = nextTicketsSearch;
        }

        if (faqSearchQuery.value !== nextFaqsSearch) {
            skipFaqsSearchWatch = true;
            faqSearchQuery.value = nextFaqsSearch;
        }

        if (statusFilter.value !== nextStatus) {
            skipStatusFilterWatch = true;
            statusFilter.value = nextStatus;
        }

        if (priorityFilter.value !== nextPriority) {
            skipPriorityFilterWatch = true;
            priorityFilter.value = nextPriority;
        }

        if (assigneeFilter.value !== nextAssignee) {
            skipAssigneeFilterWatch = true;
            assigneeFilter.value = nextAssignee;
        }

        if (dateFromFilter.value !== nextDateFrom) {
            skipDateFromFilterWatch = true;
            dateFromFilter.value = nextDateFrom;
        }

        if (dateToFilter.value !== nextDateTo) {
            skipDateToFilterWatch = true;
            dateToFilter.value = nextDateTo;
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

watch(statusFilter, () => {
    if (skipStatusFilterWatch) {
        skipStatusFilterWatch = false;
        return;
    }

    navigateTicketsWithFilters();
});

watch(priorityFilter, () => {
    if (skipPriorityFilterWatch) {
        skipPriorityFilterWatch = false;
        return;
    }

    navigateTicketsWithFilters();
});

watch(assigneeFilter, () => {
    if (skipAssigneeFilterWatch) {
        skipAssigneeFilterWatch = false;
        return;
    }

    navigateTicketsWithFilters();
});

watch(dateFromFilter, () => {
    if (skipDateFromFilterWatch) {
        skipDateFromFilterWatch = false;
        return;
    }

    navigateTicketsWithFilters();
});

watch(dateToFilter, () => {
    if (skipDateToFilterWatch) {
        skipDateToFilterWatch = false;
        return;
    }

    navigateTicketsWithFilters();
});

const clearTicketFilter = (key: TicketFilterChipKey) => {
    if (key === 'status') {
        skipStatusFilterWatch = true;
        statusFilter.value = '';
    } else if (key === 'priority') {
        skipPriorityFilterWatch = true;
        priorityFilter.value = '';
    } else if (key === 'assignee') {
        skipAssigneeFilterWatch = true;
        assigneeFilter.value = '';
    } else if (key === 'date_range') {
        skipDateFromFilterWatch = true;
        skipDateToFilterWatch = true;
        dateFromFilter.value = '';
        dateToFilter.value = '';
    }

    navigateTicketsWithFilters();
};

const resetTicketFilters = () => {
    if (!hasTicketFilters.value) {
        return;
    }

    skipStatusFilterWatch = true;
    skipPriorityFilterWatch = true;
    skipAssigneeFilterWatch = true;
    skipDateFromFilterWatch = true;
    skipDateToFilterWatch = true;

    statusFilter.value = '';
    priorityFilter.value = '';
    assigneeFilter.value = '';
    dateFromFilter.value = '';
    dateToFilter.value = '';

    navigateTicketsWithFilters();
};

const reorderFaq = (faq: FaqItem, direction: 'up' | 'down') => {
    router.patch(
        route('acp.support.faqs.reorder', { faq: faq.id }),
        { direction },
        {
            ...quickActionVisitOptions,
            onSuccess: () => {
                const action = direction === 'up' ? 'up' : 'down';
                toast.success(`FAQ order moved ${action}.`);
            },
            onError: (errors) => {
                const message =
                    typeof errors.direction === 'string'
                        ? errors.direction
                        : 'Unable to reorder FAQ.';

                toast.error(message);
            },
        },
    );
};

const publishFaq = (faq: FaqItem) => {
    router.patch(
        route('acp.support.faqs.publish', { faq: faq.id }),
        {},
        {
            ...quickActionVisitOptions,
            onSuccess: () => {
                toast.success('FAQ published.');
            },
            onError: (errors) => {
                const message =
                    typeof errors.published === 'string'
                        ? errors.published
                        : 'Unable to publish FAQ.';

                toast.error(message);
            },
        },
    );
};

const unpublishFaq = (faq: FaqItem) => {
    router.patch(
        route('acp.support.faqs.unpublish', { faq: faq.id }),
        {},
        {
            ...quickActionVisitOptions,
            onSuccess: () => {
                toast.success('FAQ unpublished.');
            },
            onError: (errors) => {
                const message =
                    typeof errors.published === 'string'
                        ? errors.published
                        : 'Unable to unpublish FAQ.';

                toast.error(message);
            },
        },
    );
};
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
                            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                                <div class="flex w-full flex-col gap-3">
                                    <div class="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center md:gap-2">
                                        <Input
                                            v-model="ticketSearchQuery"
                                            placeholder="Search tickets..."
                                            class="w-full md:w-60 lg:w-72"
                                        />
                                        <div class="flex w-full flex-wrap items-center gap-2">
                                            <select
                                                v-model="statusFilter"
                                                :class="[selectFilterClass, 'md:w-40']"
                                            >
                                                <option
                                                    v-for="option in ticketStatusOptions"
                                                    :key="option.value === '' ? 'status-all' : option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                            <select
                                                v-model="priorityFilter"
                                                :class="[selectFilterClass, 'md:w-40']"
                                            >
                                                <option
                                                    v-for="option in ticketPriorityOptions"
                                                    :key="option.value === '' ? 'priority-all' : option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                            <select
                                                v-model="assigneeFilter"
                                                :class="[selectFilterClass, 'md:w-48']"
                                            >
                                                <option
                                                    v-for="option in assigneeOptions"
                                                    :key="option.value === '' ? 'assignee-all' : option.value"
                                                    :value="option.value"
                                                >
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                            <Input
                                                v-model="dateFromFilter"
                                                type="date"
                                                class="w-full md:w-40"
                                            />
                                            <Input
                                                v-model="dateToFilter"
                                                type="date"
                                                class="w-full md:w-40"
                                            />
                                        </div>
                                    </div>
                                    <div
                                        v-if="hasTicketFilters"
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Button
                                            v-for="chip in activeTicketFilterChips"
                                            :key="chip.key"
                                            variant="outline"
                                            size="sm"
                                            class="flex items-center gap-1"
                                            @click="clearTicketFilter(chip.key)"
                                        >
                                            {{ chip.label }}
                                            <X class="h-4 w-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="flex items-center gap-1"
                                            @click="resetTicketFilters"
                                        >
                                            Clear filters
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2 md:w-auto md:flex-row md:items-center md:justify-end md:gap-2">
                                    <Link
                                        v-if="editSupport || createSupport"
                                        :href="route('acp.support.ticket-categories.index')"
                                    >
                                        <Button variant="outline" class="w-full md:w-auto">
                                            Manage categories
                                        </Button>
                                    </Link>
                                    <Link
                                        v-if="createSupport"
                                        :href="route('acp.support.tickets.create')"
                                    >
                                        <Button variant="secondary" class="w-full text-sm text-white md:w-auto bg-green-500 hover:bg-green-600">
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
                                            <TableHead>Category</TableHead>
                                            <TableHead class="text-center">Status</TableHead>
                                            <TableHead class="text-center">Priority</TableHead>
                                            <TableHead class="text-center">Assigned</TableHead>
                                            <TableHead class="text-center">Created</TableHead>
                                            <TableHead class="text-center">Resolved</TableHead>
                                            <TableHead class="text-center">Resolver</TableHead>
                                            <TableHead class="text-center">CSAT</TableHead>
                                            <TableHead class="text-center">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="t in ticketItems"
                                            :key="t.id"
                                        >
                                            <TableCell>{{ t.id }}</TableCell>
                                            <TableCell>{{ t.subject }}</TableCell>
                                            <TableCell>{{ t.user?.nickname ?? '—' }}</TableCell>
                                            <TableCell>{{ t.category?.name ?? '—' }}</TableCell>
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
                                            <TableCell class="text-center">{{ t.assignee?.nickname || '—' }}</TableCell>
                                            <TableCell class="text-center">{{ t.created_at ? fromNow(t.created_at) : '—' }}</TableCell>
                                            <TableCell class="text-center">
                                                <span v-if="t.resolved_at" :title="formatDate(t.resolved_at)">
                                                    {{ fromNow(t.resolved_at) }}
                                                </span>
                                                <span v-else>—</span>
                                            </TableCell>
                                            <TableCell class="text-center">{{ t.resolver?.nickname || '—' }}</TableCell>
                                            <TableCell class="text-center">
                                                {{
                                                    typeof t.customer_satisfaction_rating === 'number'
                                                        ? `${t.customer_satisfaction_rating}/5`
                                                        : '—'
                                                }}
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button variant="outline" size="icon">
                                                            <Ellipsis />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent>
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuGroup v-if="viewSupport">
                                                            <Link :href="route('acp.support.tickets.show', { ticket: t.id })">
                                                                <DropdownMenuItem>
                                                                    <Eye class="mr-2" /> View conversation
                                                                </DropdownMenuItem>
                                                            </Link>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator
                                                            v-if="
                                                                viewSupport &&
                                                                (assignSupport || prioritySupport || editSupport || statusSupport || deleteSupport)
                                                            "
                                                        />
                                                        <DropdownMenuSeparator v-else-if="assignSupport||prioritySupport" />
                                                        <DropdownMenuGroup v-if="assignSupport||prioritySupport">
                                                        <DropdownMenuItem
                                                            v-if="assignSupport"
                                                            @select="openAssignDialog(t)"
                                                        >
                                                            <UserPlus class="h-8 w-8" />
                                                            <span>Add Users</span>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            v-if="prioritySupport"
                                                            @select="openPriorityDialog(t)"
                                                        >
                                                            <SquareChevronUp class="h-8 w-8" />
                                                            <span>Update Priority</span>
                                                        </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="editSupport" />
                                                        <DropdownMenuGroup v-if="editSupport">
                                                            <Link :href="route('acp.support.tickets.edit', { ticket: t.id })">
                                                                <DropdownMenuItem>
                                                                    <Pencil class="mr-2" /> Edit
                                                                </DropdownMenuItem>
                                                            </Link>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="statusSupport" />
                                                        <DropdownMenuGroup v-if="statusSupport">
                                                            <DropdownMenuItem
                                                                v-if="t.status !== 'open'"
                                                                class="text-green-500"
                                                                @select="openStatusDialog(t, 'open')"
                                                            >
                                                                <Ticket class="mr-2" /> Open Ticket
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                v-if="t.status !== 'pending'"
                                                                class="text-blue-500"
                                                                @select="openStatusDialog(t, 'pending')"
                                                            >
                                                                <HelpCircle class="mr-2" /> Mark as pending
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                v-if="t.status === 'open'"
                                                                class="text-red-500"
                                                                @select="openStatusDialog(t, 'closed')"
                                                            >
                                                                <TicketX class="mr-2" /> Close Ticket
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="deleteSupport" />
                                                        <DropdownMenuGroup v-if="deleteSupport">
                                                            <DropdownMenuItem
                                                                @select="$inertia.delete(route('acp.support.tickets.destroy', { ticket: t.id }))"
                                                            >
                                                                <Trash2 class="mr-2" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="!ticketItems.length">
                                            <TableCell colspan="8" class="text-center text-gray-500">
                                                No tickets found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                                <div class="text-sm text-muted-foreground text-center md:text-left">
                                    {{ ticketsRangeLabel }}
                                </div>
                                <Pagination
                                    v-if="ticketsMeta.total > 0"
                                    v-slot="{ page, pageCount }"
                                    v-model:page="ticketsPage"
                                    :items-per-page="Math.max(ticketsMeta.per_page, 1)"
                                    :total="ticketsMeta.total"
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

                    <!-- FAQs Tab -->
                    <TabsContent value="faq">
                        <div class="rounded-xl border p-4 space-y-4">

                            <!-- Header: Search & Create -->
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                <h2 class="text-lg font-semibold">FAQ Management</h2>
                                <div class="flex w-full flex-col gap-2 md:w-auto md:flex-row md:items-center md:gap-2">
                                    <Input
                                        v-model="faqSearchQuery"
                                        placeholder="Search FAQs..."
                                        class="w-full md:w-64"
                                    />
                                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-end md:gap-2">
                                        <Link
                                            v-if="editSupport || createSupport"
                                            :href="route('acp.support.faq-categories.index')"
                                        >
                                            <Button variant="outline" class="w-full md:w-auto">
                                                Manage categories
                                            </Button>
                                        </Link>
                                        <Link
                                            v-if="createSupport"
                                            :href="route('acp.support.faqs.create')"
                                        >
                                            <Button variant="secondary" class="w-full text-sm text-white md:w-auto bg-green-500 hover:bg-green-600">
                                                Create FAQ
                                            </Button>
                                        </Link>
                                    </div>
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
                                            <TableHead>Category</TableHead>
                                            <TableHead>Order</TableHead>
                                            <TableHead>Published</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="f in faqItems"
                                            :key="f.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                        >
                                            <TableCell>{{ f.id }}</TableCell>
                                            <TableCell>{{ f.question }}</TableCell>
                                            <TableCell>{{ f.answer }}</TableCell>
                                            <TableCell>{{ f.category?.name ?? '—' }}</TableCell>
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
                                                            <DropdownMenuItem
                                                                @select="reorderFaq(f, 'up')"
                                                            >
                                                                <MoveUp class="mr-2" /> Move Up
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                @select="reorderFaq(f, 'down')"
                                                            >
                                                                <MoveDown class="mr-2" /> Move Down
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuGroup v-if="publishSupport">
                                                            <DropdownMenuItem
                                                                v-if="!f.published"
                                                                @select="publishFaq(f)"
                                                            >
                                                                <Eye class="mr-2" /> Publish
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                v-if="f.published"
                                                                @select="unpublishFaq(f)"
                                                            >
                                                                <EyeOff class="mr-2" /> Unpublish
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="editSupport||deleteSupport" />
                                                        <DropdownMenuGroup v-if="editSupport||deleteSupport">
                                                            <Link
                                                                v-if="editSupport"
                                                                :href="route('acp.support.faqs.edit', { faq: f.id })"
                                                            >
                                                                <DropdownMenuItem>
                                                                    <Pencil class="mr-2" /> Edit
                                                                </DropdownMenuItem>
                                                            </Link>
                                                            <DropdownMenuItem
                                                                v-if="deleteSupport"
                                                                @select="$inertia.delete(route('acp.support.faqs.destroy', { faq: f.id }))"
                                                            >
                                                                <Trash2 class="mr-2" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuGroup>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="!faqItems.length">
                                        <TableCell colspan="7" class="text-center text-gray-500">
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
                                    v-if="faqsMeta.total > 0"
                                    v-slot="{ page, pageCount }"
                                    v-model:page="faqsPage"
                                    :items-per-page="Math.max(faqsMeta.per_page, 1)"
                                    :total="faqsMeta.total"
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
                </Tabs>
            </div>
            <Toaster theme="dark" richColors />
        </AdminLayout>

        <Dialog :open="assignDialogOpen" @update:open="handleAssignDialogChange">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-6" @submit.prevent="submitAssignForm">
                    <DialogHeader>
                        <DialogTitle>Assign ticket</DialogTitle>
                        <DialogDescription v-if="assignDialogTicket">
                            Assign ticket <span class="font-medium">#{{ assignDialogTicket.id }}</span> to an agent or choose
                            <span class="font-medium">Unassigned</span> to clear the current assignee.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-3">
                        <div class="grid gap-2">
                            <Label for="assign-ticket-agent">Assigned agent</Label>
                            <select
                                id="assign-ticket-agent"
                                v-model="assignForm.assigned_to"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                            >
                                <option :value="null">Unassigned</option>
                                <option v-for="agent in props.assignableAgents" :key="agent.id" :value="agent.id">
                                    {{ agent.nickname }} ({{ agent.email }})
                                </option>
                            </select>
                            <InputError :message="assignForm.errors.assigned_to" />
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="secondary" @click="handleAssignDialogChange(false)">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="assignForm.processing">
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="priorityDialogOpen" @update:open="handlePriorityDialogChange">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Update ticket priority</DialogTitle>
                    <DialogDescription v-if="priorityDialogTicket">
                        Select the priority for ticket <span class="font-medium">#{{ priorityDialogTicket.id }}</span>.
                        Current priority:
                        <span class="font-medium">{{ formatPriority(priorityDialogTicket.priority) }}</span>.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="priorityDialogTicket" class="grid gap-3 py-2">
                    <div class="grid gap-2">
                        <Label for="priority-dialog-select">Priority</Label>
                        <select
                            id="priority-dialog-select"
                            v-model="priorityDialogNextPriority"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            <option v-for="priority in priorityLevels" :key="priority" :value="priority">
                                {{ formatPriority(priority) }}
                            </option>
                        </select>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="handlePriorityDialogChange(false)">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        :disabled="
                            !priorityDialogTicket ||
                            !priorityDialogNextPriority ||
                            priorityDialogNextPriority === priorityDialogTicket.priority
                        "
                        @click="confirmPriorityUpdate"
                    >
                        Save changes
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="statusDialogOpen" @update:open="handleStatusDialogChange">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Update ticket status</DialogTitle>
                    <DialogDescription v-if="statusDialogTicket && statusDialogStatus">
                        Are you sure you want to {{ statusDialogActionLabel }} for
                        ticket <span class="font-medium">#{{ statusDialogTicket.id }}</span>?
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="handleStatusDialogChange(false)">
                        Cancel
                    </Button>
                    <Button type="button" :disabled="!statusDialogTicket || !statusDialogStatus" @click="confirmStatusUpdate">
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
