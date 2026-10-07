<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CountrySelect from '@/components/commerce/CountrySelect.vue';
import type { AddressFormValue } from '@/types/commerce';

/**
 * The fields of one address. Errors are looked up as `${errorPrefix}.${field}`,
 * which is how the server names them (`shipping_address.line1`), or as the bare
 * field name when no prefix is given (the address book).
 */
const props = defineProps<{
    modelValue: AddressFormValue;
    countries: string[];
    errors?: Record<string, string | undefined>;
    errorPrefix?: string;
    idPrefix: string;
    /** Tax depends on the state or province for this country, so it is not optional. */
    regionRequired?: boolean;
}>();

const emit = defineEmits<{ (event: 'update:modelValue', value: AddressFormValue): void }>();

const set = (field: keyof AddressFormValue, value: string) => emit('update:modelValue', { ...props.modelValue, [field]: value });

const error = (field: keyof AddressFormValue) => props.errors?.[props.errorPrefix ? `${props.errorPrefix}.${field}` : field];
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2 sm:col-span-2">
            <Label :for="`${idPrefix}-name`">Full name</Label>
            <Input
                :id="`${idPrefix}-name`"
                :model-value="modelValue.name"
                autocomplete="name"
                :aria-invalid="!!error('name') || undefined"
                @update:model-value="set('name', String($event))"
            />
            <InputError :message="error('name')" />
        </div>

        <div class="grid gap-2 sm:col-span-2">
            <Label :for="`${idPrefix}-company`">Company <span class="text-muted-foreground">(optional)</span></Label>
            <Input
                :id="`${idPrefix}-company`"
                :model-value="modelValue.company"
                autocomplete="organization"
                @update:model-value="set('company', String($event))"
            />
            <InputError :message="error('company')" />
        </div>

        <div class="grid gap-2 sm:col-span-2">
            <Label :for="`${idPrefix}-line1`">Address</Label>
            <Input
                :id="`${idPrefix}-line1`"
                :model-value="modelValue.line1"
                autocomplete="address-line1"
                :aria-invalid="!!error('line1') || undefined"
                @update:model-value="set('line1', String($event))"
            />
            <InputError :message="error('line1')" />
        </div>

        <div class="grid gap-2 sm:col-span-2">
            <Label :for="`${idPrefix}-line2`">Apartment, suite, etc. <span class="text-muted-foreground">(optional)</span></Label>
            <Input
                :id="`${idPrefix}-line2`"
                :model-value="modelValue.line2"
                autocomplete="address-line2"
                @update:model-value="set('line2', String($event))"
            />
            <InputError :message="error('line2')" />
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-city`">City</Label>
            <Input
                :id="`${idPrefix}-city`"
                :model-value="modelValue.city"
                autocomplete="address-level2"
                :aria-invalid="!!error('city') || undefined"
                @update:model-value="set('city', String($event))"
            />
            <InputError :message="error('city')" />
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-region`">
                State / province
                <span v-if="!regionRequired" class="text-muted-foreground">(optional)</span>
            </Label>
            <Input
                :id="`${idPrefix}-region`"
                :model-value="modelValue.region"
                autocomplete="address-level1"
                :required="regionRequired"
                :aria-invalid="!!error('region') || undefined"
                @update:model-value="set('region', String($event))"
            />
            <InputError :message="error('region')" />
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-postal_code`">Postal code</Label>
            <Input
                :id="`${idPrefix}-postal_code`"
                :model-value="modelValue.postal_code"
                autocomplete="postal-code"
                :aria-invalid="!!error('postal_code') || undefined"
                @update:model-value="set('postal_code', String($event))"
            />
            <InputError :message="error('postal_code')" />
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-country`">Country</Label>
            <CountrySelect
                :id="`${idPrefix}-country`"
                :model-value="modelValue.country"
                :countries="countries"
                :invalid="!!error('country')"
                @update:model-value="set('country', $event)"
            />
            <InputError :message="error('country')" />
        </div>

        <div class="grid gap-2 sm:col-span-2">
            <Label :for="`${idPrefix}-phone`">Phone <span class="text-muted-foreground">(optional)</span></Label>
            <Input
                :id="`${idPrefix}-phone`"
                type="tel"
                :model-value="modelValue.phone"
                autocomplete="tel"
                @update:model-value="set('phone', String($event))"
            />
            <InputError :message="error('phone')" />
        </div>
    </div>
</template>
