<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Create ticket', href: route('acp.support.tickets.create') },
];

const priorityOptions = [
    { label: 'Low', value: 'low' },
    { label: 'Medium', value: 'medium' },
    { label: 'High', value: 'high' },
];

const form = useForm({
    subject: '',
    body: '',
    priority: 'medium',
});

const handleSubmit = () => {
    form.post(route('acp.support.tickets.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create support ticket" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create support ticket</h1>
                        <p class="text-sm text-muted-foreground">
                            Log a new request on behalf of the community and prioritise it for the support team.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save ticket</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Ticket details</CardTitle>
                                <CardDescription>
                                    Provide a clear subject and description so agents can act quickly.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="subject">Subject</Label>
                                <Input
                                    id="subject"
                                    v-model="form.subject"
                                    type="text"
                                    autocomplete="off"
                                    required
                                />
                                <InputError :message="form.errors.subject" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="body">Description</Label>
                                <Textarea
                                    id="body"
                                    v-model="form.body"
                                    placeholder="Describe the issue or request in detail."
                                    class="min-h-48"
                                    required
                                />
                                <InputError :message="form.errors.body" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Classification</CardTitle>
                            <CardDescription>Set the ticket priority to help the triage process.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="priority">Priority</Label>
                                <select
                                    id="priority"
                                    v-model="form.priority"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.priority" />
                            </div>

                            <p class="text-sm text-muted-foreground">
                                Tickets marked as high priority appear at the top of work queues for faster response times.
                            </p>
                        </CardContent>
                        <CardFooter class="justify-end">
                            <Button type="submit" :disabled="form.processing">Save ticket</Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
