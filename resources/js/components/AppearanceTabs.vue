<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { cn } from '@/lib/utils';
import { Check, Monitor, Moon, Sun } from '@lucide/vue';

interface Props {
    class?: string;
}

const { class: containerClass = '' } = defineProps<Props>();

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div role="radiogroup" aria-label="Colour theme" :class="cn('grid max-w-xl grid-cols-3 gap-3', containerClass)">
        <button
            v-for="{ value, Icon, label } in options"
            :key="value"
            type="button"
            role="radio"
            :aria-checked="appearance === value"
            :class="
                cn(
                    'group overflow-hidden rounded-lg border bg-card text-left transition-colors hover:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none',
                    appearance === value && 'border-primary ring-1 ring-primary',
                )
            "
            @click="updateAppearance(value)"
        >
            <!-- Miniature preview of the theme -->
            <div class="flex h-20 border-b">
                <div v-if="value !== 'dark'" :class="cn('flex-1 space-y-1.5 bg-white p-2.5', value === 'system' && 'flex-[0.5]')">
                    <div class="h-1.5 w-8 rounded-full bg-zinc-300" />
                    <div class="h-1.5 w-12 rounded-full bg-zinc-200" />
                    <div class="mt-2 h-4 w-10 rounded bg-indigo-500" />
                </div>
                <div v-if="value !== 'light'" :class="cn('flex-1 space-y-1.5 bg-zinc-950 p-2.5', value === 'system' && 'flex-[0.5]')">
                    <div class="h-1.5 w-8 rounded-full bg-zinc-700" />
                    <div class="h-1.5 w-12 rounded-full bg-zinc-800" />
                    <div class="mt-2 h-4 w-10 rounded bg-indigo-400" />
                </div>
            </div>
            <div class="flex items-center gap-2 px-3 py-2 text-sm font-medium">
                <component :is="Icon" class="size-4 text-muted-foreground" />
                {{ label }}
                <Check v-if="appearance === value" class="ml-auto size-4 text-primary" />
            </div>
        </button>
    </div>
</template>
