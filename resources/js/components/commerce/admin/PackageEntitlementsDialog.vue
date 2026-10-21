<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { EntitlementChoice } from '@/types/entitlements';
import type { TebexPackageRow } from '@/types/tebex';
import { Link, useForm } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps<{
    /** The package being edited; the dialog is open while there is one. */
    package: TebexPackageRow | null;
    /** Everything a package can be set to give. */
    choices: EntitlementChoice[];
}>();

const emit = defineEmits<{ close: [] }>();

/** What is ticked, and for a limit how much one unit adds (kept as text while it is typed). */
const picked = reactive<Record<number, { on: boolean; value: string }>>({});
const form = useForm({ entitlements: [] as { id: number; value: number | null }[] });

watch(
    () => props.package,
    (pkg) => {
        form.clearErrors();

        for (const key of Object.keys(picked)) {
            delete picked[Number(key)];
        }

        for (const choice of props.choices) {
            const given = pkg?.entitlements.find((entitlement) => entitlement.id === choice.id);

            picked[choice.id] = { on: given !== undefined, value: given?.value != null ? String(given.value) : '' };
        }
    },
    { immediate: true },
);

const firstError = () => Object.values(form.errors)[0];

const save = () => {
    if (!props.package) {
        return;
    }

    form.entitlements = props.choices
        .filter((choice) => picked[choice.id]?.on)
        .map((choice) => ({
            id: choice.id,
            value: choice.type === 'limit' && picked[choice.id].value !== '' ? Number(picked[choice.id].value) : null,
        }));

    form.put(route('acp.commerce.tebex.packages.entitlements.update', props.package.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <Dialog :open="package !== null" @update:open="(value) => !value && emit('close')">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>What {{ package?.name }} gives</DialogTitle>
                <DialogDescription>
                    What the buyer's account in the shop is given when they pay for it. It applies to purchases made from now on; orders already paid
                    keep what they were given.
                </DialogDescription>
            </DialogHeader>

            <p v-if="!choices.length" class="text-sm text-muted-foreground">
                There is nothing to give yet.
                <Link :href="route('acp.commerce.entitlements.index')" class="underline">Add an entitlement</Link> first.
            </p>

            <form v-else id="package-entitlements-form" class="space-y-3" @submit.prevent="save">
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
                        adds
                        <Input
                            v-model="picked[choice.id].value"
                            type="number"
                            min="1"
                            max="1000000"
                            class="w-24"
                            :aria-label="`How much one unit of the package adds to ${choice.label}`"
                        />
                        per unit
                    </div>
                </div>
                <InputError :message="firstError()" />
            </form>

            <DialogFooter>
                <Button type="button" variant="outline" @click="emit('close')">Cancel</Button>
                <Button v-if="choices.length" type="submit" form="package-entitlements-form" :disabled="form.processing">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
