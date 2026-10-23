<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { ApiScopeOption } from '@/types/api-keys';
import { computed, useId } from 'vue';

/**
 * The scopes an API key can be limited to, to tick. `allowFull` adds "Full access" (every scope, including any added later), which is
 * what staff can give; it stands in for the rest, so ticking it clears them.
 */
withDefaults(defineProps<{ scopes: ApiScopeOption[]; allowFull?: boolean }>(), { allowFull: false });

const selected = defineModel<string[]>({ required: true });

const FULL = '*';
const id = useId();

const full = computed(() => selected.value.includes(FULL));

const has = (key: string) => selected.value.includes(key);

const toggle = (key: string, on: boolean | 'indeterminate') => {
    selected.value = on === true ? [...selected.value.filter((scope) => scope !== FULL), key] : selected.value.filter((scope) => scope !== key);
};

const toggleFull = (on: boolean | 'indeterminate') => {
    selected.value = on === true ? [FULL] : [];
};
</script>

<template>
    <fieldset class="space-y-3">
        <div v-if="allowFull" class="flex items-start gap-3 rounded-md border border-border p-3">
            <Checkbox :id="`${id}-full`" class="mt-0.5" :model-value="full" @update:model-value="toggleFull" />
            <Label :for="`${id}-full`" class="flex-col items-start gap-0.5 font-normal">
                <span class="font-medium">Full access</span>
                <span class="text-xs text-muted-foreground"
                    >Every scope, including any added later. Choose it only for a key you trust completely.</span
                >
            </Label>
        </div>

        <div class="space-y-2" :class="{ 'opacity-50': full }">
            <div v-for="scope in scopes" :key="scope.key" class="flex items-start gap-3">
                <Checkbox
                    :id="`${id}-${scope.key}`"
                    class="mt-0.5"
                    :model-value="full || has(scope.key)"
                    :disabled="full"
                    @update:model-value="(on) => toggle(scope.key, on)"
                />
                <Label :for="`${id}-${scope.key}`" class="flex-col items-start gap-0.5 font-normal">
                    <span class="font-medium"
                        >{{ scope.label }} <code class="text-xs font-normal text-muted-foreground">{{ scope.key }}</code></span
                    >
                    <span class="text-xs text-muted-foreground">{{ scope.description }}</span>
                </Label>
            </div>
        </div>
    </fieldset>
</template>
