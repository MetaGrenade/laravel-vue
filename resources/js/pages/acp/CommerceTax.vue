<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CountrySelect from '@/components/commerce/CountrySelect.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { countryName } from '@/lib/address';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Pencil, Percent, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

interface TaxRate {
    id: number;
    name: string;
    country: string;
    region: string | null;
    rate: string;
    applies_to_shipping: boolean;
    is_active: boolean;
}

const props = defineProps<{
    rates: TaxRate[];
    countries: string[];
    can: { create: boolean; edit: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Tax rates', href: route('acp.commerce.tax-rates.index') },
];

const anyActive = computed(() => props.rates.some((rate) => rate.is_active));

const where = (rate: TaxRate) => {
    const country = rate.country === '*' ? 'Everywhere else' : countryName(rate.country);

    return rate.region ? `${country} · ${rate.region}` : country;
};

const dialogOpen = ref(false);
const editing = ref<TaxRate | null>(null);

const form = useForm({
    name: '',
    country: '',
    region: '',
    rate: '',
    applies_to_shipping: true,
    is_active: true,
});

const isFallback = computed(() => form.country === '*');

// Inertia's reset() goes back to the last *submitted* values, so a new dialog would open pre-filled
// with whatever was saved before. Blank defaults are set explicitly instead.
const blank = () => ({ name: '', country: '', region: '', rate: '', applies_to_shipping: true, is_active: true });

const openNew = () => {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
    dialogOpen.value = true;
};

const openEdit = (rate: TaxRate) => {
    editing.value = rate;
    form.clearErrors();
    form.name = rate.name;
    form.country = rate.country;
    form.region = rate.region ?? '';
    form.rate = rate.rate;
    form.applies_to_shipping = rate.applies_to_shipping;
    form.is_active = rate.is_active;
    dialogOpen.value = true;
};

const save = () => {
    // A region only means something for a specific country.
    form.transform((data) => ({ ...data, region: data.country === '*' ? '' : data.region }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(route('acp.commerce.tax-rates.update', editing.value.id), options);
    } else {
        form.post(route('acp.commerce.tax-rates.store'), options);
    }
};

const pendingDelete = ref<TaxRate | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.tax-rates.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tax rates" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Percent class="size-5" /> Tax rates</h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Prices are tax-exclusive: tax is added at checkout for where the order goes. Every active rate for a country applies, and
                            several rates add together (for example a federal and a provincial tax). A rate for "everywhere else" is the fallback for
                            countries with no rate of their own.
                        </p>
                    </div>
                    <Button v-if="props.can.create" @click="openNew"><Plus class="size-4" /> Add rate</Button>
                </div>

                <div v-if="!anyActive" class="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm" role="status">
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
                    <p><strong>No tax is being charged.</strong> Add a rate for each country you need to collect tax in.</p>
                </div>

                <Card>
                    <CardContent class="pt-6">
                        <Table v-if="props.rates.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Where</TableHead>
                                    <TableHead>Rate</TableHead>
                                    <TableHead>On shipping</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead v-if="props.can.edit || props.can.delete" class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="rate in props.rates" :key="rate.id">
                                    <TableCell class="font-medium">{{ rate.name }}</TableCell>
                                    <TableCell>{{ where(rate) }}</TableCell>
                                    <TableCell class="tabular-nums">{{ rate.rate }}%</TableCell>
                                    <TableCell>{{ rate.applies_to_shipping ? 'Yes' : 'No' }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="rate.is_active ? 'outline' : 'secondary'">{{
                                            rate.is_active ? 'Active' : 'Inactive'
                                        }}</Badge>
                                    </TableCell>
                                    <TableCell v-if="props.can.edit || props.can.delete" class="text-right">
                                        <div class="flex justify-end gap-1">
                                            <Button
                                                v-if="props.can.edit"
                                                variant="ghost"
                                                size="icon"
                                                :aria-label="`Edit ${rate.name}`"
                                                @click="openEdit(rate)"
                                            >
                                                <Pencil class="size-4" />
                                            </Button>
                                            <Button
                                                v-if="props.can.delete"
                                                variant="ghost"
                                                size="icon"
                                                class="text-destructive"
                                                :aria-label="`Delete ${rate.name}`"
                                                @click="pendingDelete = rate"
                                            >
                                                <Trash2 class="size-4" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else class="py-8 text-center text-sm text-muted-foreground">No tax rates yet.</p>
                    </CardContent>
                </Card>

                <p class="text-xs text-muted-foreground">
                    Individual products can be marked as not taxable on the Commerce page. This table suits simple setups: there are no thresholds,
                    product-category rates or tax-inclusive prices.
                </p>
            </div>

            <Dialog v-model:open="dialogOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit tax rate' : 'Add tax rate' }}</DialogTitle>
                        <DialogDescription>Charged on top of prices at checkout.</DialogDescription>
                    </DialogHeader>

                    <form id="tax-form" class="space-y-5" @submit.prevent="save">
                        <div class="grid gap-2">
                            <Label for="tax-name">Name</Label>
                            <Input
                                id="tax-name"
                                v-model="form.name"
                                maxlength="120"
                                placeholder="VAT"
                                :aria-invalid="!!form.errors.name || undefined"
                            />
                            <p class="text-xs text-muted-foreground">Shown to the customer, for example "VAT (20%)".</p>
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tax-country">Country</Label>
                            <CountrySelect
                                id="tax-country"
                                v-model="form.country"
                                :countries="props.countries"
                                any-label="Everywhere else (fallback)"
                                :invalid="!!form.errors.country"
                            />
                            <InputError :message="form.errors.country" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tax-region">State or province <span class="text-muted-foreground">(optional)</span></Label>
                            <Input
                                id="tax-region"
                                v-model="form.region"
                                maxlength="120"
                                :disabled="isFallback"
                                placeholder="California"
                                :aria-invalid="!!form.errors.region || undefined"
                            />
                            <p class="text-xs text-muted-foreground">
                                Leave blank to apply to the whole country. Customers type their state, so use the name they would (matched without
                                regard to case); checkout then requires one in this country.
                            </p>
                            <InputError :message="form.errors.region" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tax-rate">Rate (%)</Label>
                            <Input
                                id="tax-rate"
                                v-model="form.rate"
                                inputmode="decimal"
                                class="w-32"
                                placeholder="20"
                                :aria-invalid="!!form.errors.rate || undefined"
                            />
                            <InputError :message="form.errors.rate" />
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch id="tax-shipping" v-model="form.applies_to_shipping" />
                            <Label for="tax-shipping" class="font-normal">Also charge this tax on shipping</Label>
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch id="tax-active" v-model="form.is_active" />
                            <Label for="tax-active" class="font-normal">Active</Label>
                        </div>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Cancel</Button>
                        <Button type="submit" form="tax-form" :disabled="form.processing">Save rate</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                :open="pendingDelete !== null"
                title="Delete this tax rate?"
                :description="`Orders will no longer be charged ${pendingDelete?.name ?? 'this tax'}. Orders already placed keep what they were charged.`"
                confirm-label="Delete rate"
                @update:open="(open) => !open && (pendingDelete = null)"
                @confirm="remove"
                @cancel="pendingDelete = null"
            />
        </AdminLayout>
    </AppLayout>
</template>
