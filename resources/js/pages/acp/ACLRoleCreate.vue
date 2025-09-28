<script setup lang="ts">
import { computed } from 'vue';
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
import { Checkbox } from '@/components/ui/checkbox';

const props = defineProps<{
    permissions: Array<{
        id: number;
        name: string;
        guard_name: string;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Access Control', href: route('acp.acl.index') },
    { title: 'Create role', href: route('acp.acl.roles.create') },
];

const guardNames = computed(() => {
    const guards = new Set(['web']);
    props.permissions.forEach(permission => guards.add(permission.guard_name));
    return Array.from(guards);
});

const form = useForm({
    name: '',
    guard_name: 'web',
    permissions: [] as string[],
});

const togglePermission = (permissionName: string, checked: boolean | string) => {
    const isChecked = checked === true || checked === 'indeterminate';

    if (isChecked) {
        if (!form.permissions.includes(permissionName)) {
            form.permissions.push(permissionName);
        }
    } else {
        form.permissions = form.permissions.filter(name => name !== permissionName);
    }
};

const handleSubmit = () => {
    form.post(route('acp.acl.roles.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Create role" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6 w-full" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Create role</h1>
                        <p class="text-sm text-muted-foreground">
                            Define a new role and assign permissions that control what it can access.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.acl.index')">Cancel</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Create role</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <div class="flex flex-col gap-6">
                        <Card>
                            <CardHeader class="relative overflow-hidden">
                                <PlaceholderPattern class="absolute inset-0 opacity-10" />
                                <div class="relative space-y-1">
                                    <CardTitle>Role details</CardTitle>
                                    <CardDescription>Give the role a name and assign a guard.</CardDescription>
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
                                    <Input id="guard_name" v-model="form.guard_name" type="text" list="guards" required />
                                    <datalist id="guards">
                                        <option v-for="guard in guardNames" :key="guard" :value="guard">{{ guard }}</option>
                                    </datalist>
                                    <InputError :message="form.errors.guard_name" />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Permissions</CardTitle>
                                <CardDescription>Select the permissions this role should include.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div v-if="props.permissions.length === 0" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                    No permissions are currently defined. Create permissions first to assign them to this role.
                                </div>
                                <div v-else class="grid gap-3">
                                    <div
                                        v-for="permission in props.permissions"
                                        :key="permission.id"
                                        class="flex items-start gap-3 rounded-md border p-3"
                                    >
                                        <Checkbox
                                            :id="`permission-${permission.id}`"
                                            :checked="form.permissions.includes(permission.name)"
                                            @update:checked="value => togglePermission(permission.name, value)"
                                        />
                                        <div class="grid gap-1">
                                            <Label :for="`permission-${permission.id}`" class="font-medium leading-none">
                                                {{ permission.name }}
                                            </Label>
                                            <p class="text-xs text-muted-foreground">Guard: {{ permission.guard_name }}</p>
                                        </div>
                                    </div>
                                </div>
                                <InputError :message="form.errors.permissions" />
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Tips</CardTitle>
                            <CardDescription>Keep permissions focused so roles stay easy to manage.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm text-muted-foreground">
                            <p>Use guards to separate access between application contexts (such as web or api).</p>
                            <p>
                                Combine roles with user assignments in the Users ACP to grant access to individuals or teams.
                            </p>
                        </CardContent>
                        <CardFooter class="justify-end">
                            <Button type="submit" :disabled="form.processing">Create role</Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
