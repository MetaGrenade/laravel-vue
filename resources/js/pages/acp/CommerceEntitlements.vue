<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Capabilities } from '@/types/catalogue';
import type { EntitlementKind, EntitlementRow } from '@/types/entitlements';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

defineProps<{
    entitlements: EntitlementRow[];
    can: Capabilities;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Entitlements', href: route('acp.commerce.entitlements.index') },
];

const selectClass = 'rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden';

const open = ref(false);
const editing = ref<EntitlementRow | null>(null);
const form = useForm({ key: '', label: '', description: '', type: 'feature' as EntitlementKind });

const openNew = () => {
    editing.value = null;
    form.defaults({ key: '', label: '', description: '', type: 'feature' }).reset();
    form.clearErrors();
    open.value = true;
};

const openEdit = (entitlement: EntitlementRow) => {
    editing.value = entitlement;
    form.defaults({ key: entitlement.key, label: entitlement.label, description: entitlement.description ?? '', type: entitlement.type }).reset();
    form.clearErrors();
    open.value = true;
};

const save = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(route('acp.commerce.entitlements.update', editing.value.id), options);
    } else {
        form.post(route('acp.commerce.entitlements.store'), options);
    }
};

const pendingDelete = ref<EntitlementRow | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.entitlements.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Entitlements" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><BadgeCheck class="size-5" /> Entitlements</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        What a purchase or a plan can give an account: a feature switched on, or a limit raised. You choose which packages give which
                        on the <Link :href="route('acp.commerce.tebex.index')" class="underline">Tebex page</Link>, and which plans on
                        <Link :href="route('acp.billing.plans.index')" class="underline">Subscription plans</Link>. A purchase gives them when the
                        order is paid, and takes them back if it is refunded in full, a dispute over it is lost, or the recurring payment it came with
                        ends. A plan gives them while the subscription is live.
                    </p>
                </div>

                <Card>
                    <CardHeader class="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle>All entitlements</CardTitle>
                            <CardDescription>
                                Code asks for one by its key, for example <code class="font-mono text-xs">entitled:reports</code> on a route.
                            </CardDescription>
                        </div>
                        <Button v-if="can.create" size="sm" @click="openNew"><Plus class="size-4" /> Add</Button>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="entitlements.length" class="divide-y">
                            <li v-for="entitlement in entitlements" :key="entitlement.id" class="flex items-center justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 font-medium">
                                        {{ entitlement.label }}
                                        <Badge variant="outline">{{ entitlement.type_label }}</Badge>
                                    </p>
                                    <p class="truncate font-mono text-xs text-muted-foreground">{{ entitlement.key }}</p>
                                    <p v-if="entitlement.description" class="mt-0.5 text-sm text-muted-foreground">{{ entitlement.description }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        Given by {{ entitlement.packages_count }} {{ entitlement.packages_count === 1 ? 'package' : 'packages' }} and
                                        {{ entitlement.plans_count }} {{ entitlement.plans_count === 1 ? 'plan' : 'plans' }} · held through
                                        {{ entitlement.grants_count }} {{ entitlement.grants_count === 1 ? 'purchase' : 'purchases' }}
                                    </p>
                                </div>
                                <div v-if="can.edit || can.delete" class="flex shrink-0 gap-1">
                                    <Button
                                        v-if="can.edit"
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Edit ${entitlement.label}`"
                                        @click="openEdit(entitlement)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        v-if="can.delete"
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive"
                                        :aria-label="`Delete ${entitlement.label}`"
                                        @click="pendingDelete = entitlement"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">None yet. Add one, then choose which packages give it.</p>
                    </CardContent>
                </Card>
            </div>

            <Dialog v-model:open="open">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit entitlement' : 'Add an entitlement' }}</DialogTitle>
                        <DialogDescription>
                            {{
                                editing
                                    ? 'The key and the kind are fixed once it exists: code and the grants already made depend on them.'
                                    : 'The key is what code asks for, and it cannot be changed afterwards.'
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <form id="entitlement-form" class="space-y-5" @submit.prevent="save">
                        <div class="grid gap-2">
                            <Label for="entitlement-label">Name</Label>
                            <Input id="entitlement-label" v-model="form.label" maxlength="255" :aria-invalid="!!form.errors.label || undefined" />
                            <InputError :message="form.errors.label" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entitlement-key">Key</Label>
                            <Input
                                id="entitlement-key"
                                v-model="form.key"
                                maxlength="64"
                                placeholder="team.seats"
                                class="font-mono"
                                :disabled="editing !== null"
                                :aria-invalid="!!form.errors.key || undefined"
                            />
                            <InputError :message="form.errors.key" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entitlement-type">Kind</Label>
                            <select id="entitlement-type" v-model="form.type" :class="selectClass" :disabled="editing !== null">
                                <option value="feature">Feature: on or off</option>
                                <option value="limit">Limit: a number that adds up</option>
                            </select>
                            <InputError :message="form.errors.type" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="entitlement-description">Description <span class="text-muted-foreground">(optional)</span></Label>
                            <Textarea id="entitlement-description" v-model="form.description" rows="3" maxlength="1000" />
                            <InputError :message="form.errors.description" />
                        </div>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                        <Button type="submit" form="entitlement-form" :disabled="form.processing">Save</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                :open="pendingDelete !== null"
                :title="`Delete ${pendingDelete?.label ?? 'this entitlement'}?`"
                description="Packages that give it stop giving it. It cannot be deleted while any purchase still holds it."
                confirm-label="Delete entitlement"
                @update:open="(value) => !value && (pendingDelete = null)"
                @confirm="remove"
                @cancel="pendingDelete = null"
            />
        </AdminLayout>
    </AppLayout>
</template>
