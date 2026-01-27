<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { PlusCircle, Trash2 } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Polls ACP', href: route('acp.polls.index') },
    { title: 'Create poll', href: route('acp.polls.create') },
];

const form = useForm({
    title: '',
    slug: '',
    description: '',
    status: 'draft',
    allow_multiple: false,
    starts_at: '',
    ends_at: '',
    options: [
        { label: '' },
        { label: '' },
    ],
});

const addOption = () => {
    form.options.push({ label: '' });
};

const removeOption = (index: number) => {
    if (form.options.length <= 2) {
        return;
    }

    form.options.splice(index, 1);
};

const handleSubmit = () => {
    form.post(route('acp.polls.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create poll" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create poll</h1>
                        <p class="text-sm text-muted-foreground">
                            Launch a poll or survey to capture quick feedback from your community.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.polls.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save poll</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Poll details</CardTitle>
                            <CardDescription>
                                Provide the question, visibility settings, and schedule for the poll.
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="title">Title</Label>
                            <Input id="title" v-model="form.title" type="text" autocomplete="off" required />
                            <InputError :message="form.errors.title" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="slug">Slug</Label>
                            <Input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                autocomplete="off"
                                placeholder="Leave blank to auto-generate"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                rows="4"
                                placeholder="Add context or additional instructions for voters."
                            />
                            <InputError :message="form.errors.description" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="status">Status</Label>
                            <select
                                id="status"
                                v-model="form.status"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="closed">Closed</option>
                            </select>
                            <InputError :message="form.errors.status" />
                        </div>

                        <div class="flex items-center justify-between rounded-lg border border-dashed border-muted-foreground/30 p-4">
                            <div>
                                <p class="text-sm font-medium">Allow multiple selections</p>
                                <p class="text-xs text-muted-foreground">
                                    Enable voters to select more than one option.
                                </p>
                            </div>
                            <Switch v-model:checked="form.allow_multiple" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="starts_at">Starts at</Label>
                                <Input id="starts_at" v-model="form.starts_at" type="datetime-local" />
                                <InputError :message="form.errors.starts_at" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="ends_at">Ends at</Label>
                                <Input id="ends_at" v-model="form.ends_at" type="datetime-local" />
                                <InputError :message="form.errors.ends_at" />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Answer options</CardTitle>
                        <CardDescription>List at least two options for participants to choose from.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div v-for="(option, index) in form.options" :key="index" class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div class="flex-1">
                                <Label :for="`option-${index}`" class="sr-only">Option {{ index + 1 }}</Label>
                                <Input
                                    :id="`option-${index}`"
                                    v-model="option.label"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="Option label"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="form.options.length <= 2"
                                @click="removeOption(index)"
                            >
                                <Trash2 class="h-4 w-4" />
                                Remove
                            </Button>
                        </div>
                        <InputError :message="form.errors.options" />
                        <Button type="button" variant="secondary" size="sm" @click="addOption">
                            <PlusCircle class="h-4 w-4" />
                            Add option
                        </Button>
                    </CardContent>
                    <CardFooter class="justify-end gap-2">
                        <Button type="submit" :disabled="form.processing">Save poll</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
