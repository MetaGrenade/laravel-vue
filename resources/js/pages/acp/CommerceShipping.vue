<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CountryListInput from '@/components/commerce/CountryListInput.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { countryName } from '@/lib/address';
import { formatMoney } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Pencil, Plus, Trash2, Truck } from '@lucide/vue';
import { ref } from 'vue';

interface Rate {
    id: number;
    name: string;
    description: string | null;
    amount: string;
    min_subtotal: string | null;
    max_subtotal: string | null;
    position: number;
    is_active: boolean;
}

interface Zone {
    id: number;
    name: string;
    countries: string[];
    position: number;
    is_active: boolean;
    rates: Rate[];
}

const props = defineProps<{
    zones: Zone[];
    configured: boolean;
    currency: string;
    countries: string[];
    can: { create: boolean; edit: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Shipping', href: route('acp.commerce.shipping.index') },
];

const money = (amount: string | null) => formatMoney(amount ?? '0', props.currency);

const countrySummary = (zone: Zone) => {
    if (zone.countries.includes('*')) {
        return 'Everywhere else';
    }

    const names = zone.countries.map((code) => countryName(code)).sort((a, b) => a.localeCompare(b));

    return names.length > 6 ? `${names.slice(0, 6).join(', ')} and ${names.length - 6} more` : names.join(', ');
};

const limits = (rate: Rate) => {
    if (rate.min_subtotal && rate.max_subtotal) {
        return `Orders of ${money(rate.min_subtotal)} to ${money(rate.max_subtotal)}`;
    }

    if (rate.min_subtotal) {
        return `Orders of ${money(rate.min_subtotal)} or more`;
    }

    if (rate.max_subtotal) {
        return `Orders up to ${money(rate.max_subtotal)}`;
    }

    return 'Any order';
};

const hasNoActiveRates = (zone: Zone) => zone.is_active && !zone.rates.some((rate) => rate.is_active);

/* Zones ------------------------------------------------------------------ */

const zoneDialogOpen = ref(false);
const editingZone = ref<Zone | null>(null);

const zoneForm = useForm({
    name: '',
    countries: [] as string[],
    position: '' as number | string,
    is_active: true,
});

// Inertia's reset() goes back to the last *submitted* values, so a new dialog would open pre-filled
// with whatever was saved before. Blank defaults are set explicitly instead.
const blankZone = () => ({ name: '', countries: [] as string[], position: '' as number | string, is_active: true });

const openNewZone = () => {
    editingZone.value = null;
    zoneForm.defaults(blankZone()).reset();
    zoneForm.clearErrors();
    zoneDialogOpen.value = true;
};

const openEditZone = (zone: Zone) => {
    editingZone.value = zone;
    zoneForm.clearErrors();
    zoneForm.name = zone.name;
    zoneForm.countries = [...zone.countries];
    zoneForm.position = zone.position;
    zoneForm.is_active = zone.is_active;
    zoneDialogOpen.value = true;
};

const saveZone = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            zoneDialogOpen.value = false;
        },
    };

    if (editingZone.value) {
        zoneForm.put(route('acp.commerce.shipping.zones.update', editingZone.value.id), options);
    } else {
        zoneForm.post(route('acp.commerce.shipping.zones.store'), options);
    }
};

const pendingZoneDelete = ref<Zone | null>(null);

const deleteZone = () => {
    if (!pendingZoneDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.shipping.zones.destroy', pendingZoneDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingZoneDelete.value = null;
        },
    });
};

/* Rates ------------------------------------------------------------------ */

const rateDialogOpen = ref(false);
const rateZone = ref<Zone | null>(null);
const editingRate = ref<Rate | null>(null);

const rateForm = useForm({
    name: '',
    description: '',
    amount: '',
    min_subtotal: '',
    max_subtotal: '',
    is_active: true,
});

const blankRate = () => ({ name: '', description: '', amount: '', min_subtotal: '', max_subtotal: '', is_active: true });

const openNewRate = (zone: Zone) => {
    rateZone.value = zone;
    editingRate.value = null;
    rateForm.defaults(blankRate()).reset();
    rateForm.clearErrors();
    rateDialogOpen.value = true;
};

const openEditRate = (zone: Zone, rate: Rate) => {
    rateZone.value = zone;
    editingRate.value = rate;
    rateForm.clearErrors();
    rateForm.name = rate.name;
    rateForm.description = rate.description ?? '';
    rateForm.amount = rate.amount;
    rateForm.min_subtotal = rate.min_subtotal ?? '';
    rateForm.max_subtotal = rate.max_subtotal ?? '';
    rateForm.is_active = rate.is_active;
    rateDialogOpen.value = true;
};

const saveRate = () => {
    if (!rateZone.value) {
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            rateDialogOpen.value = false;
        },
    };

    if (editingRate.value) {
        rateForm.put(route('acp.commerce.shipping.rates.update', editingRate.value.id), options);
    } else {
        rateForm.post(route('acp.commerce.shipping.rates.store', rateZone.value.id), options);
    }
};

const pendingRateDelete = ref<Rate | null>(null);

const deleteRate = () => {
    if (!pendingRateDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.shipping.rates.destroy', pendingRateDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingRateDelete.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Shipping" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Truck class="size-5" /> Shipping</h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Zones say where you deliver; each zone's rates say what delivery costs. A customer's address picks the zone, and they
                            choose among its rates. A zone that names a country is used before the "everywhere else" zone.
                        </p>
                    </div>
                    <Button v-if="props.can.create" @click="openNewZone"><Plus class="size-4" /> Add zone</Button>
                </div>

                <div
                    v-if="!props.configured"
                    class="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
                    role="status"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>
                        <strong>Shipping isn't set up.</strong> Until there is an active zone, orders are accepted for any country with no shipping
                        charge. Add a zone to charge for shipping and to limit where you deliver.
                    </p>
                </div>

                <Card v-for="zone in props.zones" :key="zone.id">
                    <CardHeader class="gap-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <CardTitle class="flex flex-wrap items-center gap-2">
                                    {{ zone.name }}
                                    <Badge :variant="zone.is_active ? 'default' : 'secondary'">{{ zone.is_active ? 'Active' : 'Inactive' }}</Badge>
                                </CardTitle>
                                <CardDescription>{{ countrySummary(zone) }} · order {{ zone.position }}</CardDescription>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Button v-if="props.can.edit" variant="outline" size="sm" @click="openEditZone(zone)"
                                    ><Pencil class="size-3.5" /> Edit zone</Button
                                >
                                <Button v-if="props.can.delete" variant="ghost" size="sm" class="text-destructive" @click="pendingZoneDelete = zone">
                                    <Trash2 class="size-3.5" /> Delete
                                </Button>
                            </div>
                        </div>

                        <p v-if="hasNoActiveRates(zone)" class="flex items-start gap-2 text-sm text-warning" role="status">
                            <CircleAlert class="mt-0.5 size-4 shrink-0" />
                            This zone has no active rates, so customers in these countries cannot order anything that needs shipping.
                        </p>
                    </CardHeader>

                    <CardContent class="space-y-3">
                        <Table v-if="zone.rates.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Rate</TableHead>
                                    <TableHead>Price</TableHead>
                                    <TableHead>Offered for</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead v-if="props.can.edit || props.can.delete" class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="rate in zone.rates" :key="rate.id">
                                    <TableCell>
                                        <div class="font-medium">{{ rate.name }}</div>
                                        <div v-if="rate.description" class="text-xs text-muted-foreground">{{ rate.description }}</div>
                                    </TableCell>
                                    <TableCell class="tabular-nums">{{ Number(rate.amount) === 0 ? 'Free' : money(rate.amount) }}</TableCell>
                                    <TableCell class="text-sm text-muted-foreground">{{ limits(rate) }}</TableCell>
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
                                                @click="openEditRate(zone, rate)"
                                            >
                                                <Pencil class="size-4" />
                                            </Button>
                                            <Button
                                                v-if="props.can.delete"
                                                variant="ghost"
                                                size="icon"
                                                class="text-destructive"
                                                :aria-label="`Delete ${rate.name}`"
                                                @click="pendingRateDelete = rate"
                                            >
                                                <Trash2 class="size-4" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else class="text-sm text-muted-foreground">No rates yet.</p>

                        <Button v-if="props.can.create" variant="outline" size="sm" @click="openNewRate(zone)"
                            ><Plus class="size-3.5" /> Add rate</Button
                        >
                    </CardContent>
                </Card>

                <p v-if="!props.zones.length" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    No shipping zones yet.
                </p>
            </div>

            <!-- Zone -->
            <Dialog v-model:open="zoneDialogOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{{ editingZone ? 'Edit zone' : 'Add zone' }}</DialogTitle>
                        <DialogDescription>A zone is a set of countries that share the same shipping rates.</DialogDescription>
                    </DialogHeader>

                    <form id="zone-form" class="space-y-5" @submit.prevent="saveZone">
                        <div class="grid gap-2">
                            <Label for="zone-name">Name</Label>
                            <Input
                                id="zone-name"
                                v-model="zoneForm.name"
                                maxlength="120"
                                placeholder="United Kingdom"
                                :aria-invalid="!!zoneForm.errors.name || undefined"
                            />
                            <InputError :message="zoneForm.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="zone-countries">Countries</Label>
                            <CountryListInput
                                id="zone-countries"
                                v-model="zoneForm.countries"
                                :countries="props.countries"
                                :invalid="!!zoneForm.errors.countries"
                            />
                            <InputError :message="zoneForm.errors.countries" />
                            <template v-for="(message, key) in zoneForm.errors" :key="key">
                                <InputError v-if="String(key).startsWith('countries.')" :message="message" />
                            </template>
                        </div>

                        <div class="grid gap-2">
                            <Label for="zone-position">Order <span class="text-muted-foreground">(optional)</span></Label>
                            <Input id="zone-position" v-model="zoneForm.position" type="number" min="0" inputmode="numeric" class="w-28" />
                            <p class="text-xs text-muted-foreground">
                                If two zones name the same country, the lower number is used. Leave blank to put it last.
                            </p>
                            <InputError :message="zoneForm.errors.position" />
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch id="zone-active" v-model="zoneForm.is_active" />
                            <Label for="zone-active" class="font-normal">Active</Label>
                        </div>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="zoneDialogOpen = false">Cancel</Button>
                        <Button type="submit" form="zone-form" :disabled="zoneForm.processing">Save zone</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Rate -->
            <Dialog v-model:open="rateDialogOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{ editingRate ? 'Edit rate' : 'Add rate' }}</DialogTitle>
                        <DialogDescription>{{ rateZone?.name }} · amounts are in {{ props.currency }}</DialogDescription>
                    </DialogHeader>

                    <form id="rate-form" class="space-y-5" @submit.prevent="saveRate">
                        <div class="grid gap-2">
                            <Label for="rate-name">Name</Label>
                            <Input
                                id="rate-name"
                                v-model="rateForm.name"
                                maxlength="120"
                                placeholder="Standard"
                                :aria-invalid="!!rateForm.errors.name || undefined"
                            />
                            <InputError :message="rateForm.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rate-description">Description <span class="text-muted-foreground">(optional)</span></Label>
                            <Input id="rate-description" v-model="rateForm.description" maxlength="255" placeholder="3–5 business days" />
                            <InputError :message="rateForm.errors.description" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rate-amount">Price</Label>
                            <Input
                                id="rate-amount"
                                v-model="rateForm.amount"
                                inputmode="decimal"
                                class="w-40"
                                placeholder="5.00"
                                :aria-invalid="!!rateForm.errors.amount || undefined"
                            />
                            <p class="text-xs text-muted-foreground">Enter 0 for free shipping.</p>
                            <InputError :message="rateForm.errors.amount" />
                        </div>

                        <fieldset class="grid gap-4 sm:grid-cols-2">
                            <legend class="mb-2 text-sm font-medium">
                                Only offer for orders <span class="font-normal text-muted-foreground">(optional)</span>
                            </legend>
                            <div class="grid gap-2">
                                <Label for="rate-min" class="font-normal">of at least</Label>
                                <Input
                                    id="rate-min"
                                    v-model="rateForm.min_subtotal"
                                    inputmode="decimal"
                                    placeholder="100.00"
                                    :aria-invalid="!!rateForm.errors.min_subtotal || undefined"
                                />
                                <InputError :message="rateForm.errors.min_subtotal" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="rate-max" class="font-normal">of at most</Label>
                                <Input
                                    id="rate-max"
                                    v-model="rateForm.max_subtotal"
                                    inputmode="decimal"
                                    placeholder="99.99"
                                    :aria-invalid="!!rateForm.errors.max_subtotal || undefined"
                                />
                                <InputError :message="rateForm.errors.max_subtotal" />
                            </div>
                            <p class="text-xs text-muted-foreground sm:col-span-2">
                                Only the items that are shipped count, so a download in the cart doesn't help a parcel qualify. For "free over 100",
                                add a rate priced 0 with a minimum of 100.
                            </p>
                        </fieldset>

                        <div class="flex items-center gap-3">
                            <Switch id="rate-active" v-model="rateForm.is_active" />
                            <Label for="rate-active" class="font-normal">Active</Label>
                        </div>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="rateDialogOpen = false">Cancel</Button>
                        <Button type="submit" form="rate-form" :disabled="rateForm.processing">Save rate</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                :open="pendingZoneDelete !== null"
                title="Delete this zone?"
                :description="`${pendingZoneDelete?.name ?? 'This zone'} and its ${pendingZoneDelete?.rates.length ?? 0} rate(s) will be deleted. Orders already placed keep what they were charged.${
                    props.zones.filter((zone) => zone.is_active).length <= 1 && pendingZoneDelete?.is_active
                        ? ' This is your last active zone: shipping will be unrestricted and free until you add another.'
                        : ''
                }`"
                confirm-label="Delete zone"
                @update:open="(open) => !open && (pendingZoneDelete = null)"
                @confirm="deleteZone"
                @cancel="pendingZoneDelete = null"
            />

            <ConfirmDialog
                :open="pendingRateDelete !== null"
                title="Delete this rate?"
                :description="`Customers will no longer be offered ${pendingRateDelete?.name ?? 'this rate'}. Orders already placed keep what they were charged.`"
                confirm-label="Delete rate"
                @update:open="(open) => !open && (pendingRateDelete = null)"
                @confirm="deleteRate"
                @cancel="pendingRateDelete = null"
            />
        </AdminLayout>
    </AppLayout>
</template>
