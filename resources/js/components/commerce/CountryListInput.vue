<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { countryName, countryOptions } from '@/lib/address';
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Edits the list of countries a shipping zone covers: pick countries one at a
 * time, add the whole EU at once, or cover "everywhere else" (stored as "*").
 */
const props = defineProps<{
    id: string;
    modelValue: string[];
    countries: string[];
    invalid?: boolean;
}>();

const emit = defineEmits<{ (event: 'update:modelValue', value: string[]): void }>();

const ANY = '*';

const EU = [
    'AT',
    'BE',
    'BG',
    'HR',
    'CY',
    'CZ',
    'DK',
    'EE',
    'FI',
    'FR',
    'DE',
    'GR',
    'HU',
    'IE',
    'IT',
    'LV',
    'LT',
    'LU',
    'MT',
    'NL',
    'PL',
    'PT',
    'RO',
    'SK',
    'SI',
    'ES',
    'SE',
];

const everywhereElse = computed(() => props.modelValue.includes(ANY));

const chosen = computed(() =>
    props.modelValue
        .filter((code) => code !== ANY)
        .map((code) => ({ code, name: countryName(code) }))
        .sort((a, b) => a.name.localeCompare(b.name)),
);

const remaining = computed(() => countryOptions(props.countries.filter((code) => !props.modelValue.includes(code))));

const pickerValue = ref('');

const add = (code: string) => {
    if (code && !props.modelValue.includes(code)) {
        emit('update:modelValue', [...props.modelValue, code]);
    }

    pickerValue.value = '';
};

const remove = (code: string) =>
    emit(
        'update:modelValue',
        props.modelValue.filter((existing) => existing !== code),
    );

const addEu = () => emit('update:modelValue', [...new Set([...props.modelValue.filter((code) => code !== ANY), ...EU])]);

const toggleEverywhere = (value: boolean | 'indeterminate') => emit('update:modelValue', value === true ? [ANY] : []);
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center gap-2">
            <Checkbox :id="`${id}-any`" :model-value="everywhereElse" @update:model-value="toggleEverywhere" />
            <Label :for="`${id}-any`" class="font-normal">Everywhere else (every country not covered by another zone)</Label>
        </div>

        <template v-if="!everywhereElse">
            <div class="flex flex-wrap items-center gap-2">
                <select
                    :id="id"
                    v-model="pickerValue"
                    :aria-invalid="invalid || undefined"
                    class="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                    @change="add(pickerValue)"
                >
                    <option value="">Add a country…</option>
                    <option v-for="option in remaining" :key="option.code" :value="option.code">{{ option.name }}</option>
                </select>
                <Button type="button" variant="outline" size="sm" @click="addEu">Add EU countries</Button>
            </div>

            <ul v-if="chosen.length" class="flex flex-wrap gap-1.5" aria-label="Countries in this zone">
                <li
                    v-for="country in chosen"
                    :key="country.code"
                    class="inline-flex items-center gap-1 rounded-full border bg-muted px-2.5 py-1 text-xs"
                >
                    {{ country.name }}
                    <button
                        type="button"
                        class="rounded-full text-muted-foreground hover:text-foreground"
                        :aria-label="`Remove ${country.name}`"
                        @click="remove(country.code)"
                    >
                        <X class="size-3" />
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">No countries yet.</p>
        </template>
    </div>
</template>
