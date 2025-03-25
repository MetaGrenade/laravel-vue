<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

// Import Lucide icons for support stats
import { MessageSquare, XCircle, CheckCircle, HelpCircle } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Support ACP',
        href: '/acp/support',
    },
];

// Dummy support statistics
const supportStats = [
    { title: 'Total Tickets', value: '150', icon: MessageSquare },
    { title: 'Open Tickets', value: '35', icon: XCircle },
    { title: 'Closed Tickets', value: '115', icon: CheckCircle },
    { title: 'FAQs', value: '20', icon: HelpCircle },
];

// Dummy data for support tickets
interface Ticket {
    id: number;
    subject: string;
    submittedBy: string;
    status: string;
    created_at: string;
}
const tickets = ref<Ticket[]>([
    { id: 1, subject: 'Unable to login', submittedBy: 'Alice', status: 'Open', created_at: '2023-01-10' },
    { id: 2, subject: 'Bug in payment module', submittedBy: 'Bob', status: 'Closed', created_at: '2023-01-12' },
    { id: 3, subject: 'Feature request: dark mode', submittedBy: 'Charlie', status: 'Open', created_at: '2023-01-15' },
    // Add more tickets as needed...
]);

// Ticket search query and computed filtered tickets
const ticketSearchQuery = ref('');
const filteredTickets = computed(() => {
    if (!ticketSearchQuery.value) return tickets.value;
    const q = ticketSearchQuery.value.toLowerCase();
    return tickets.value.filter(ticket =>
        ticket.subject.toLowerCase().includes(q) ||
        ticket.submittedBy.toLowerCase().includes(q) ||
        ticket.status.toLowerCase().includes(q)
    );
});

// Dummy data for FAQs
interface FAQ {
    id: number;
    question: string;
    answer: string;
}
const faqs = ref<FAQ[]>([
    { id: 1, question: 'How do I reset my password?', answer: 'Click on "Forgot Password" on the login page.' },
    { id: 2, question: 'Where can I find the user manual?', answer: 'The user manual is available in the Help section.' },
    { id: 3, question: 'How do I contact support?', answer: 'Email us at support@example.com.' },
    // Add more FAQs as needed...
]);

// FAQ search query and computed filtered FAQs
const faqSearchQuery = ref('');
const filteredFaqs = computed(() => {
    if (!faqSearchQuery.value) return faqs.value;
    const q = faqSearchQuery.value.toLowerCase();
    return faqs.value.filter(faq => faq.question.toLowerCase().includes(q));
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Support ACP" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Support Stats Section -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in supportStats"
                        :key="index"
                        class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ stat.value }}</div>
                        </div>
                    </div>
                </div>

                <!-- Support Ticket Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <h2 class="text-lg font-semibold mb-2 md:mb-0">Support Ticket Management</h2>
                        <div class="flex space-x-2">
                            <input
                                v-model="ticketSearchQuery"
                                type="text"
                                placeholder="Search tickets..."
                                class="rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                            <button class="rounded bg-blue-500 px-4 py-2 text-white hover:bg-blue-600">
                                Create Ticket
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse">
                            <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">ID</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Subject</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Submitted By</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Status</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Created At</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr
                                v-for="ticket in filteredTickets"
                                :key="ticket.id"
                                class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900"
                            >
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ ticket.id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ ticket.subject }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ ticket.submittedBy }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ ticket.status }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ ticket.created_at }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <button class="text-blue-500 hover:underline text-sm">View</button>
                                    <button class="ml-2 text-red-500 hover:underline text-sm">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="filteredTickets.length === 0">
                                <td colspan="6" class="px-4 py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                                    No tickets found.
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- FAQ Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <h2 class="text-lg font-semibold mb-2 md:mb-0">FAQ Management</h2>
                        <div class="flex space-x-2">
                            <input
                                v-model="faqSearchQuery"
                                type="text"
                                placeholder="Search FAQs..."
                                class="rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                            <button class="rounded bg-blue-500 px-4 py-2 text-white hover:bg-blue-600">
                                Create FAQ
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse">
                            <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">ID</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Question</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Answer</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr
                                v-for="faq in filteredFaqs"
                                :key="faq.id"
                                class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900"
                            >
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ faq.id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ faq.question }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ faq.answer }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <button class="text-blue-500 hover:underline text-sm">Edit</button>
                                    <button class="ml-2 text-red-500 hover:underline text-sm">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="filteredFaqs.length === 0">
                                <td colspan="4" class="px-4 py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                                    No FAQs found.
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
