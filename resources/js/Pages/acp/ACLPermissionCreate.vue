<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Access Control', href: route('acp.acl.index') },
    { title: 'Create permission', href: route('acp.acl.permissions.create') },
];

const form = useForm({
    name: '',
    guard_name: 'web',
});

const handleSubmit = () => {
    form.post(route('acp.acl.permissions.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create permission" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6 w-full" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create permission</h1>
                        <p class="text-sm text-muted-foreground">
                            Register a new permission that can be assigned to roles.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.acl.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Create permission</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Permission details</CardTitle>
                                <CardDescription>
                                    Permission names should be unique and describe the capability clearly.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="name">Name</Label>
                                <Input id="name" v-model="form.name" type="text" autocomplete="off" required />
                                <InputError :message="form.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="guard_name">Guard name</Label>
                                <Input id="guard_name" v-model="form.guard_name" type="text" required />
                                <InputError :message="form.errors.guard_name" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Need a reminder?</CardTitle>
                            <CardDescription>Permissions can be combined into roles for easier management.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm text-muted-foreground">
                            <p>Choose a guard that matches where the permission will be checked, such as web or api.</p>
                            <p>After creating permissions, assign them to roles from the Access Control panel.</p>
                        </CardContent>
                        <CardFooter class="justify-end">
                            <Button type="submit" :disabled="form.processing">Create permission</Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
