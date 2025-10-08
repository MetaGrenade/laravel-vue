<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Switch } from '@/components/ui/switch';

interface BadgePayload {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    points_required: number;
    is_active: boolean;
}

const props = defineProps<{ badge: BadgePayload }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: route('acp.dashboard') },
    { title: 'Badges', href: route('acp.reputation.badges.index') },
    { title: props.badge.name, href: route('acp.reputation.badges.edit', { badge: props.badge.id }) },
];

const form = useForm({
    name: props.badge.name,
    slug: props.badge.slug,
    description: props.badge.description ?? '',
    points_required: props.badge.points_required,
    is_active: props.badge.is_active,
});

const hasChanges = computed(() => form.isDirty);

const handleSubmit = () => {
    form.put(route('acp.reputation.badges.update', { badge: props.badge.id }));
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit ${props.badge.name}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit badge</h1>
                        <p class="text-sm text-muted-foreground">
                            Update the badge details or deactivate it without losing the award history.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.reputation.badges.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing || !hasChanges">
                            Save changes
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Badge details</CardTitle>
                            <CardDescription>
                                Adjust the milestone name, message, or points required.
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
                            <Input id="slug" v-model="form.slug" type="text" autocomplete="off" />
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Explain how members earn this badge."
                                class="min-h-24"
                            />
                            <InputError :message="form.errors.description" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="points_required">Points required</Label>
                            <Input
                                id="points_required"
                                v-model.number="form.points_required"
                                type="number"
                                min="0"
                                step="1"
                                required
                            />
                            <InputError :message="form.errors.points_required" />
                        </div>

                        <div class="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <div class="font-medium">Active badge</div>
                                <p class="text-sm text-muted-foreground">
                                    Inactive badges remain hidden but keep existing award history intact.
                                </p>
                            </div>
                            <Switch v-model:checked="form.is_active" />
                        </div>
                        <InputError :message="form.errors.is_active" />
                    </CardContent>
                    <CardFooter class="flex items-center justify-end gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.reputation.badges.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing || !hasChanges">
                            Save changes
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
