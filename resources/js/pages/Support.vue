<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
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
    DropdownMenuPortal,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Pin, PinOff, Ellipsis, Eye, EyeOff, Pencil, Trash2, Lock, LockOpen, TicketX, LifeBuoy
} from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support', href: '/support' },
];

// ===================
// MY TICKETS SECTION
// ===================
interface Ticket {
    id: number;
    subject: string;
    status: 'Open' | 'Closed';
    created_at: string;
}

const tickets = ref<Ticket[]>([
    { id: 1, subject: 'Unable to login', status: 'Open', created_at: '2023-07-20' },
    { id: 2, subject: 'Bug in payment module', status: 'Closed', created_at: '2023-07-22' },
    { id: 3, subject: 'Feature request: dark mode', status: 'Open', created_at: '2023-07-25' },
]);

const ticketSearchQuery = ref('');
const filteredTickets = computed(() => {
    if (!ticketSearchQuery.value) return tickets.value;
    const q = ticketSearchQuery.value.toLowerCase();
    return tickets.value.filter(ticket =>
        ticket.subject.toLowerCase().includes(q) ||
        ticket.status.toLowerCase().includes(q)
    );
});

// For new ticket submission
const newTicketSubject = ref("");
const newTicketDescription = ref("");
function submitTicket() {
    if (!newTicketSubject.value.trim() || !newTicketDescription.value.trim()) return;
    const newTicket: Ticket = {
        id: Date.now(),
        subject: newTicketSubject.value,
        status: 'Open',
        created_at: new Date().toLocaleDateString(),
    };
    tickets.value.push(newTicket);
    newTicketSubject.value = "";
    newTicketDescription.value = "";
    alert("Ticket submitted (dummy implementation)!");
}

// ===================
// FAQ SECTION
// ===================
interface FAQ {
    id: number;
    question: string;
    answer: string;
}

const faqs = ref<FAQ[]>([
    { id: 1, question: 'How do I reset my password?', answer: 'Click on "Forgot Password" on the login page and follow the instructions.' },
    { id: 2, question: 'Where can I view my ticket history?', answer: 'You can view your ticket history in the "My Tickets" tab on this page.' },
    { id: 3, question: 'How do I contact support?', answer: 'You can submit a new support ticket using the form in the "My Tickets" tab.' },
]);

const faqSearchQuery = ref('');
const filteredFaqs = computed(() => {
    if (!faqSearchQuery.value) return faqs.value;
    const q = faqSearchQuery.value.toLowerCase();
    return faqs.value.filter(faq =>
        faq.question.toLowerCase().includes(q) ||
        faq.answer.toLowerCase().includes(q)
    );
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
                <TabsList>
                    <TabsTrigger value="tickets">Support Tickets</TabsTrigger>
                    <TabsTrigger value="faq">Frequently Asked Questions</TabsTrigger>
                </TabsList>

                <!-- My Tickets Tab -->
                <TabsContent value="tickets" class="space-y-6">
                    <!-- Search and New Ticket Button -->
                    <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
<!--                        <h3 class="text-2xl font-semibold mb-4">My Tickets</h3>-->
                        <Input
                            v-model="ticketSearchQuery"
                            placeholder="Search your tickets..."
                            class="w-full md:w-1/3 md:ml-auto"
                        />
                        <a href="#create_ticket">
                            <Button variant="secondary" class="md:ml-auto cursor-pointer">
                                Create New Ticket
                            </Button>
                        </a>
                    </div>

                    <!-- Tickets Table -->
                    <div class="overflow-x-auto rounded-xl border p-4 shadow-sm">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Subject</TableHead>
                                    <TableHead class="text-center">Status</TableHead>
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
                                    <TableCell class="text-center">{{ ticket.status }}</TableCell>
                                    <TableCell class="text-center">{{ ticket.created_at }}</TableCell>
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
                                    <TableCell colspan="5" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                        No tickets found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <!-- New Ticket Submission Form -->
                    <div class="rounded-xl border p-6 shadow">
                        <h2 class="mb-4 text-xl font-bold" id="create_ticket">Submit a New Ticket</h2>
                        <div class="flex flex-col gap-4">
                            <Input
                                v-model="newTicketSubject"
                                placeholder="Ticket Subject"
                                class="w-full rounded-md"
                            />
                            <Textarea
                                v-model="newTicketDescription"
                                placeholder="Describe your issue..."
                                class="w-full rounded-md"
                            />
                            <Button variant="primary" @click="submitTicket" class="bg-green-500 hover:bg-green-600">
                                Submit Ticket
                            </Button>
                        </div>
                    </div>
                </TabsContent>

                <!-- FAQ Tab -->
                <TabsContent value="faq" class="space-y-6">
                    <!-- Search FAQ -->
                    <div class="flex">
<!--                        <h3 class="text-2xl font-semibold mb-4">Frequently Asked Questions</h3>-->
                        <Input
                            v-model="faqSearchQuery"
                            placeholder="Search FAQs..."
                            class="w-full md:w-1/3 md:ml-auto"
                        />
                    </div>

                    <!-- FAQ List -->
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
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
                                    <TableCell colspan="4" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                        No FAQs found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
