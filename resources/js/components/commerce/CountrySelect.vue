<script setup lang="ts">
import { countryOptions } from '@/lib/address';
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps<{
    id?: string;
    countries: string[];
    modelValue: string;
    invalid?: boolean;
    class?: string;
}>();

const emit = defineEmits<{ (event: 'update:modelValue', value: string): void }>();

const options = computed(() => countryOptions(props.countries));
</script>

<template>
    <select
        :id="id"
        :value="modelValue"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm dark:bg-input/30',
                'focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                props.class,
            )
        "
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
    >
        <option value="" disabled>Select a country</option>
        <option v-for="option in options" :key="option.code" :value="option.code">{{ option.name }}</option>
    </select>
</template>
