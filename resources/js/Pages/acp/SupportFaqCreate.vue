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
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Create FAQ', href: route('acp.support.faqs.create') },
];

const form = useForm({
    question: '',
    answer: '',
    order: 0,
    published: false,
});

const handleSubmit = () => {
    form.post(route('acp.support.faqs.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create FAQ" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create FAQ</h1>
                        <p class="text-sm text-muted-foreground">
                            Draft a helpful answer for common support questions to deflect future tickets.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save FAQ</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Question &amp; answer</CardTitle>
                                <CardDescription>
                                    Write concise, friendly guidance that is easy for readers to follow.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="question">Question</Label>
                                <Input id="question" v-model="form.question" type="text" autocomplete="off" required />
                                <InputError :message="form.errors.question" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="answer">Answer</Label>
                                <Textarea
                                    id="answer"
                                    v-model="form.answer"
                                    class="min-h-48"
                                    placeholder="Provide a clear answer and include any helpful links or steps."
                                    required
                                />
                                <InputError :message="form.errors.answer" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Publishing options</CardTitle>
                            <CardDescription>Set the display order and choose whether the FAQ is visible.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="order">Display order</Label>
                                <Input id="order" v-model.number="form.order" type="number" min="0" />
                                <InputError :message="form.errors.order" />
                            </div>

                            <div class="flex items-center space-x-2">
                                <Checkbox id="published" v-model:checked="form.published" />
                                <Label for="published">Publish immediately</Label>
                            </div>
                            <InputError :message="form.errors.published" />
                        </CardContent>
                        <CardFooter class="justify-end">
                            <Button type="submit" :disabled="form.processing">Save FAQ</Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
