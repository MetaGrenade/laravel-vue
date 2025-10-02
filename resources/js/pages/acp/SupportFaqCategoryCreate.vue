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
    { title: 'FAQ categories', href: route('acp.support.faq-categories.index') },
    { title: 'Create category', href: route('acp.support.faq-categories.create') },
];

const form = useForm({
    name: '',
    slug: '',
    description: '',
    order: 0,
});

const handleSubmit = () => {
    form.post(route('acp.support.faq-categories.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create FAQ category" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create FAQ category</h1>
                        <p class="text-sm text-muted-foreground">
                            Create a grouping for related help articles so you can filter FAQs by topic.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.faq-categories.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save category</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Category details</CardTitle>
                            <CardDescription>
                                Provide a clear name, optional slug, and description to help agents choose the right grouping.
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input id="name" v-model="form.name" type="text" autocomplete="off" required />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="slug">Slug</Label>
                            <Input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                autocomplete="off"
                                placeholder="Leave blank to auto-generate from the name"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Optional short summary shown to users when filtering by this category"
                                class="min-h-24"
                            />
                            <InputError :message="form.errors.description" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="order">Display order</Label>
                            <Input id="order" v-model.number="form.order" type="number" />
                            <InputError :message="form.errors.order" />
                        </div>
                    </CardContent>
                    <CardFooter class="justify-end gap-2">
                        <Button type="submit" :disabled="form.processing">Save category</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
