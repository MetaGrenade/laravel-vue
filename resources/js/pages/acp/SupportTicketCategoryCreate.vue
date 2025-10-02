<script setup lang="ts">
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Ticket categories', href: route('acp.support.ticket-categories.index') },
    { title: 'Create category', href: route('acp.support.ticket-categories.create') },
];

const form = useForm({
    name: '',
});

const handleSubmit = () => {
    form.post(route('acp.support.ticket-categories.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create ticket category" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create ticket category</h1>
                        <p class="text-sm text-muted-foreground">
                            Help agents triage requests by grouping similar tickets together.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.ticket-categories.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Create category</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Category details</CardTitle>
                            <CardDescription>
                                Give the category a short, descriptive name so the team knows when to use it.
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
                        <Button type="submit" :disabled="form.processing">Create category</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
