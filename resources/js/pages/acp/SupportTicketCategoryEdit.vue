<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';

const props = defineProps<{
    category: {
        id: number;
        name: string;
        tickets_count: number;
        created_at: string | null;
        updated_at: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Ticket categories', href: route('acp.support.ticket-categories.index') },
    { title: `Category #${props.category.id}`, href: route('acp.support.ticket-categories.edit', { category: props.category.id }) },
];

const form = useForm({
    name: props.category.name,
});

const { formatDate } = useUserTimezone();
const createdAt = computed(() => props.category.created_at ? formatDate(props.category.created_at, 'MMM D, YYYY h:mm A') : '—');
const updatedAt = computed(() => props.category.updated_at ? formatDate(props.category.updated_at, 'MMM D, YYYY h:mm A') : '—');

const handleSubmit = () => {
    form.put(route('acp.support.ticket-categories.update', { category: props.category.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Edit ticket category" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit ticket category</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the name to make it easier for the support team to file new tickets.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.ticket-categories.index')">Back to categories</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Category details</CardTitle>
                                <CardDescription>
                                    Keep category names short and action-oriented so they’re easy to scan.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    autocomplete="off"
                                    required
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                        </CardContent>
                        <CardFooter class="justify-end gap-2">
                            <Button variant="outline" as-child>
                                <Link :href="route('acp.support.ticket-categories.index')">Cancel</Link>
                            </Button>
                            <Button type="submit" :disabled="form.processing">Save changes</Button>
                        </CardFooter>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Category usage</CardTitle>
                            <CardDescription>A quick snapshot of how this category is being used.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm text-muted-foreground">
                            <div class="flex items-center justify-between">
                                <span class="text-foreground">Tickets assigned</span>
                                <span class="font-semibold text-foreground">{{ props.category.tickets_count }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-foreground">Created</span>
                                <p>{{ createdAt }}</p>
                            </div>
                            <div>
                                <span class="font-medium text-foreground">Last updated</span>
                                <p>{{ updatedAt }}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
