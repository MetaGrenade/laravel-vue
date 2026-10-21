<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { EntitlementChoice, PackageEntitlement } from '@/types/entitlements';
import { Link, useForm } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    title: string;
    description: string;
    /** What it gives now. */
    given: PackageEntitlement[];
    /** Everything it can be set to give. */
    choices: EntitlementChoice[];
    /** Where the whole list is saved to (a PUT). */
    url: string | null;
    /** The words round a limit's amount: "adds [ 5 ] per unit", "gives [ 5 ]". */
    amountBefore: string;
    amountAfter?: string;
    amountLabel: (choice: EntitlementChoice) => string;
}>();

const emit = defineEmits<{ close: [] }>();

/** What is ticked, and for a limit how much (kept as text while it is typed). */
const picked = reactive<Record<number, { on: boolean; value: string }>>({});
const form = useForm({ entitlements: [] as { id: number; value: number | null }[] });

watch(
    () => props.open,
    () => {
        form.clearErrors();

        for (const key of Object.keys(picked)) {
            delete picked[Number(key)];
        }

        for (const choice of props.choices) {
            const given = props.given.find((entitlement) => entitlement.id === choice.id);

            picked[choice.id] = { on: given !== undefined, value: given?.value != null ? String(given.value) : '' };
        }
    },
    { immediate: true },
);

const firstError = () => Object.values(form.errors)[0];

const save = () => {
    if (!props.url) {
        return;
    }

    form.entitlements = props.choices
        .filter((choice) => picked[choice.id]?.on)
        .map((choice) => ({
            id: choice.id,
            value: choice.type === 'limit' && picked[choice.id].value !== '' ? Number(picked[choice.id].value) : null,
        }));

    form.put(props.url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => !value && emit('close')">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <p v-if="!choices.length" class="text-sm text-muted-foreground">
                There is nothing to give yet.
                <Link :href="route('acp.commerce.entitlements.index')" class="underline">Add an entitlement</Link> first.
            </p>

            <form v-else id="entitlement-picker-form" class="space-y-3" @submit.prevent="save">
                <div v-for="choice in choices" :key="choice.id" class="flex items-center justify-between gap-3 rounded-md border p-3">
                    <label class="flex min-w-0 items-center gap-3">
                        <Checkbox
                            :model-value="picked[choice.id]?.on ?? false"
                            :aria-label="`Give ${choice.label}`"
                            @update:model-value="(value) => (picked[choice.id].on = value === true)"
                        />
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ choice.label }}</span>
                            <span class="block truncate font-mono text-xs text-muted-foreground">{{ choice.key }}</span>
                        </span>
                    </label>
                    <div
                        v-if="choice.type === 'limit' && picked[choice.id]?.on"
                        class="flex shrink-0 items-center gap-2 text-sm text-muted-foreground"
                    >
                        {{ amountBefore }}
                        <Input v-model="picked[choice.id].value" type="number" min="1" max="1000000" class="w-24" :aria-label="amountLabel(choice)" />
                        {{ amountAfter }}
                    </div>
                </div>
                <InputError :message="firstError()" />
            </form>

            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('close')">Cancel</Button>
                <Button v-if="choices.length" type="submit" form="entitlement-picker-form" :disabled="form.processing">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
