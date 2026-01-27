<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import dayjs from 'dayjs';

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

interface PollOption {
    id: number;
    label: string;
    votes_count: number;
}

interface PollPayload {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    status: string;
    allow_multiple: boolean;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    total_votes: number;
    options: PollOption[];
}

const props = defineProps<{
    poll: PollPayload;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Polls ACP', href: route('acp.polls.index') },
    { title: props.poll.title, href: route('acp.polls.edit', { poll: props.poll.id }) },
];

const toLocalInput = (value: string | null) => {
    if (!value) {
        return '';
    }

    return dayjs(value).local().format('YYYY-MM-DDTHH:mm');
};

const form = useForm({
    title: props.poll.title,
    slug: props.poll.slug,
    description: props.poll.description ?? '',
    status: props.poll.status,
    allow_multiple: props.poll.allow_multiple,
    starts_at: toLocalInput(props.poll.starts_at),
    ends_at: toLocalInput(props.poll.ends_at),
    options: props.poll.options.map((option) => ({
        id: option.id,
        label: option.label,
    })),
});

const totalVotes = computed(() => props.poll.total_votes);

const optionPercent = (votes: number) => {
    if (totalVotes.value === 0) {
        return 0;
    }

    return Math.round((votes / totalVotes.value) * 1000) / 10;
};

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
    form.put(route('acp.polls.update', { poll: props.poll.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit poll: ${poll.title}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit poll</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the poll details, refresh answer options, and monitor live responses.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.polls.index')">Back to polls</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Poll details</CardTitle>
                            <CardDescription>Review the poll question, schedule, and visibility settings.</CardDescription>
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
                            <Input id="slug" v-model="form.slug" type="text" autocomplete="off" />
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <Textarea id="description" v-model="form.description" rows="4" />
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
                                    Enable voters to choose more than one option.
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
                        <CardDescription>Maintain the options available to voters.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div v-for="(option, index) in form.options" :key="option.id ?? index" class="flex flex-col gap-2 sm:flex-row sm:items-center">
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
                    <CardFooter class="justify-end">
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </CardFooter>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Live results</CardTitle>
                        <CardDescription>Review current vote totals for this poll.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="text-sm text-muted-foreground">
                            Total votes: <span class="font-semibold text-foreground">{{ totalVotes }}</span>
                        </div>
                        <div class="space-y-3">
                            <div v-for="option in props.poll.options" :key="option.id" class="space-y-1">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="font-medium">{{ option.label }}</span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ option.votes_count }} votes · {{ optionPercent(option.votes_count) }}%
                                    </span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-primary"
                                        :style="{ width: `${optionPercent(option.votes_count)}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
