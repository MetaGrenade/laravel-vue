<script setup lang="ts">
import AddressFields from '@/components/commerce/AddressFields.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import { addressLines, emptyAddress } from '@/lib/address';
import type { AddressFormValue, SavedAddress } from '@/types/commerce';
import { Head, router, useForm } from '@inertiajs/vue3';
import { MapPin, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    addresses: SavedAddress[];
    countries: string[];
}>();

const dialogOpen = ref(false);
const editing = ref<SavedAddress | null>(null);

const form = useForm({
    label: '',
    is_default: false,
    address: emptyAddress(props.countries.length === 1 ? props.countries[0] : ''),
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const openNew = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.address = emptyAddress(props.countries.length === 1 ? props.countries[0] : '');
    form.is_default = props.addresses.length === 0;
    dialogOpen.value = true;
};

const openEdit = (address: SavedAddress) => {
    editing.value = address;
    form.clearErrors();
    form.label = address.label ?? '';
    form.is_default = address.is_default;
    form.address = {
        name: address.name,
        company: address.company ?? '',
        line1: address.line1,
        line2: address.line2 ?? '',
        city: address.city,
        region: address.region ?? '',
        postal_code: address.postal_code ?? '',
        country: address.country,
        phone: address.phone ?? '',
    } satisfies AddressFormValue;
    dialogOpen.value = true;
};

const save = () => {
    // The server expects the address fields at the top level, beside the label.
    form.transform((data) => ({ label: data.label, is_default: data.is_default, ...data.address }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(route('settings.addresses.update', editing.value.id), options);
    } else {
        form.post(route('settings.addresses.store'), options);
    }
};

const makeDefault = (address: SavedAddress) => router.put(route('settings.addresses.default', address.id), {}, { preserveScroll: true });

const pendingDelete = ref<SavedAddress | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('settings.addresses.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Addresses', href: '/settings/addresses' }]">
        <Head title="Addresses" />

        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <HeadingSmall
                        title="Addresses"
                        description="Saved addresses make checkout faster. Orders keep their own copy, so changing one here never alters an order."
                    />
                    <Button @click="openNew"><Plus class="size-4" /> Add address</Button>
                </div>

                <div v-if="props.addresses.length" class="grid gap-4 sm:grid-cols-2">
                    <Card v-for="address in props.addresses" :key="address.id">
                        <CardContent class="space-y-3 pt-6">
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2 font-medium">
                                    <MapPin class="size-4 text-muted-foreground" />
                                    {{ address.label || 'Address' }}
                                </span>
                                <Badge v-if="address.is_default" variant="secondary">Default</Badge>
                            </div>

                            <address class="text-sm text-muted-foreground not-italic">
                                <span v-for="(line, index) in addressLines(address)" :key="index" class="block">{{ line }}</span>
                                <span v-if="address.phone" class="block">{{ address.phone }}</span>
                            </address>

                            <div class="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" @click="openEdit(address)"><Pencil class="size-3.5" /> Edit</Button>
                                <Button v-if="!address.is_default" variant="ghost" size="sm" @click="makeDefault(address)">Make default</Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                    :aria-label="`Remove ${address.label || address.line1}`"
                                    @click="pendingDelete = address"
                                >
                                    <Trash2 class="size-3.5" /> Remove
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <p v-else class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">You haven't saved an address yet.</p>
            </div>

            <Dialog v-model:open="dialogOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit address' : 'Add address' }}</DialogTitle>
                        <DialogDescription>Where should we deliver to, or bill?</DialogDescription>
                    </DialogHeader>

                    <form id="address-form" class="space-y-5" @submit.prevent="save">
                        <div class="grid gap-2">
                            <Label for="address-label">Label <span class="text-muted-foreground">(optional, for example Home or Work)</span></Label>
                            <Input id="address-label" v-model="form.label" maxlength="60" />
                            <InputError :message="form.errors.label" />
                        </div>

                        <AddressFields v-model="form.address" :countries="props.countries" :errors="errors" id-prefix="book" />

                        <div class="flex items-center gap-2">
                            <Checkbox id="address-default" v-model="form.is_default" />
                            <Label for="address-default" class="font-normal">Use as my default address</Label>
                        </div>
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Cancel</Button>
                        <Button type="submit" form="address-form" :disabled="form.processing">Save address</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                :open="pendingDelete !== null"
                title="Remove this address?"
                description="Orders you have already placed keep their own copy of the address."
                confirm-label="Remove"
                @update:open="(open) => !open && (pendingDelete = null)"
                @confirm="remove"
                @cancel="pendingDelete = null"
            />
        </SettingsLayout>
    </AppLayout>
</template>
