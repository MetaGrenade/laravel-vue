<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

// Import shadcn-vue Input and Button components
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';

// Import shadcn-vue Table components
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';

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
                        class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
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

                <Tabs default-value="tickets" class="w-full">
                    <TabsList>
                        <TabsTrigger value="tickets">Support Tickets</TabsTrigger>
                        <TabsTrigger value="faq">Frequently Asked Questions</TabsTrigger>
                    </TabsList>

                    <TabsContent value="tickets">
                        <!-- Support Ticket Management Section -->
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                                <h2 class="text-lg font-semibold mb-2 md:mb-0">Support Ticket Management</h2>
                                <div class="flex space-x-2">
                                    <Input
                                        v-model="ticketSearchQuery"
                                        placeholder="Search tickets..."
                                        class="w-full rounded-md"
                                    />
                                    <Button variant="secondary">Create Ticket</Button>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Subject</TableHead>
                                            <TableHead>Submitted By</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Created At</TableHead>
                                            <TableHead>Actions</TableHead>
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
                                            <TableCell>{{ ticket.submittedBy }}</TableCell>
                                            <TableCell>{{ ticket.status }}</TableCell>
                                            <TableCell>{{ ticket.created_at }}</TableCell>
                                            <TableCell>
                                                <button class="text-blue-500 hover:underline text-sm">View</button>
                                                <button class="ml-2 text-red-500 hover:underline text-sm">Delete</button>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="filteredTickets.length === 0">
                                            <TableCell colspan="6" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                                No tickets found.
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </TabsContent>

                    <TabsContent value="faq">
                        <!-- FAQ Management Section -->
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                                <h2 class="text-lg font-semibold mb-2 md:mb-0">FAQ Management</h2>
                                <div class="flex space-x-2">
                                    <Input
                                        v-model="faqSearchQuery"
                                        placeholder="Search FAQs..."
                                        class="w-full rounded-md"
                                    />
                                    <Button variant="secondary">Create FAQ</Button>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Question</TableHead>
                                            <TableHead>Answer</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="faq in filteredFaqs"
                                            :key="faq.id"
                                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                        >
                                            <TableCell>{{ faq.id }}</TableCell>
                                            <TableCell>{{ faq.question }}</TableCell>
                                            <TableCell>{{ faq.answer }}</TableCell>
                                            <TableCell>
                                                <button class="text-blue-500 hover:underline text-sm">Edit</button>
                                                <button class="ml-2 text-red-500 hover:underline text-sm">Delete</button>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow v-if="filteredFaqs.length === 0">
                                            <TableCell colspan="4" class="text-center text-sm text-gray-600 dark:text-gray-300">
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
