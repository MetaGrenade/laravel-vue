<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/orderStatus';
import type { Capabilities, LicenceTarget } from '@/types/catalogue';
import { router } from '@inertiajs/vue3';
import { KeyRound, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    productId: number;
    targets: LicenceTarget[];
    rules: { max_per_upload: number; max_length: number };
    hasVariants: boolean;
    can: Capabilities;
}>();

// What is typed into each target's box, the problem found with it, and the keys ticked for deletion.
const drafts = reactive<Record<string, string>>({});
const problems = reactive<Record<string, string>>({});
const selected = reactive<Record<string, number[]>>({});
const busy = ref<string | null>(null);

// How many keys the box holds: a repeated line is added once, so it is counted once.
const lines = (target: LicenceTarget) =>
    new Set(
        (drafts[target.key] ?? '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter((line) => line !== ''),
    ).size;

// ----- Switch on and off ------------------------------------------------------------------------

const enable = (target: LicenceTarget) => {
    busy.value = target.key;

    router.post(
        route('acp.commerce.licence-keys.enable', props.productId),
        { product_variant_id: target.variant_id },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
};

const disableOpen = ref<LicenceTarget | null>(null);

const disable = () => {
    const target = disableOpen.value;

    if (!target?.stock_id) {
        return;
    }

    router.post(
        route('acp.commerce.licence-keys.disable', target.stock_id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                disableOpen.value = null;
            },
        },
    );
};

// ----- Add keys -----------------------------------------------------------------------------------

const add = (target: LicenceTarget) => {
    if (!target.stock_id || (drafts[target.key] ?? '').trim() === '') {
        return;
    }

    busy.value = target.key;
    delete problems[target.key];

    router.post(
        route('acp.commerce.licence-keys.store', target.stock_id),
        { keys: drafts[target.key] },
        {
            preserveScroll: true,
            onSuccess: () => {
                drafts[target.key] = '';
            },
            onError: (errors) => {
                problems[target.key] = String(errors.keys ?? Object.values(errors)[0] ?? 'The keys could not be added.');
            },
            onFinish: () => (busy.value = null),
        },
    );
};

// ----- Delete unused keys -------------------------------------------------------------------------

const isSelected = (target: LicenceTarget, id: number) => (selected[target.key] ?? []).includes(id);

const toggle = (target: LicenceTarget, id: number, on: boolean | 'indeterminate') => {
    const current = selected[target.key] ?? [];

    selected[target.key] = on === true ? [...current, id] : current.filter((value) => value !== id);
};

const deleting = ref<LicenceTarget | null>(null);
const deletingCount = computed(() => (deleting.value ? (selected[deleting.value.key] ?? []).length : 0));

const remove = () => {
    const target = deleting.value;

    if (!target?.stock_id) {
        return;
    }

    router.delete(route('acp.commerce.licence-keys.destroy', target.stock_id), {
        data: { ids: selected[target.key] ?? [] },
        preserveScroll: true,
        onSuccess: () => {
            selected[target.key] = [];
        },
        onFinish: () => {
            deleting.value = null;
        },
    });
};

const stateOf = (target: LicenceTarget) => {
    if (!target.enabled || !target.counts) {
        return { label: 'Off', variant: 'secondary' as const };
    }

    return target.quantity > 0
        ? { label: `${target.quantity} to sell`, variant: 'default' as const }
        : { label: 'Sold out', variant: 'destructive' as const };
};
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-muted-foreground">
            Sell software licences, game codes or gift codes: add the keys here and each unit bought is given its own key, shown on the customer's
            order page as soon as the order is paid. The number you can sell is the number of unused keys, so it always matches what is in the pool
            and you cannot oversell. Keys are stored encrypted, and are never sent to the browser in full (they are only revealed to their buyer).
            Keep a safe copy of the app's <code class="text-xs">APP_KEY</code>: without it the keys cannot be read.
        </p>

        <p v-if="hasVariants" class="text-sm text-muted-foreground">
            This product has variants, and each one has its own pool, so a Standard and a Pro licence never share keys.
        </p>

        <section v-for="target in targets" :key="target.key" class="space-y-4 rounded-lg border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 font-medium">
                    <KeyRound class="size-4 text-muted-foreground" aria-hidden="true" /> {{ target.label }}
                </h3>
                <Badge :variant="stateOf(target).variant">{{ stateOf(target).label }}</Badge>
            </div>

            <!-- Not selling keys from here yet -->
            <template v-if="!target.enabled">
                <p class="text-sm text-muted-foreground">
                    Stock here is counted by hand.
                    <template v-if="target.stock_id && target.quantity !== 0">
                        Switching on licence keys replaces the current count ({{ target.quantity }}) with the number of keys in the pool, which starts
                        at zero, so add keys straight away.
                    </template>
                    <template v-else>Switch it on to sell keys instead.</template>
                </p>
                <Button v-if="can.edit" size="sm" variant="outline" :disabled="busy === target.key" @click="enable(target)">Sell licence keys</Button>
            </template>

            <!-- A pool -->
            <template v-else-if="target.counts">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-muted-foreground">Unused</dt>
                        <dd class="text-xl font-semibold tabular-nums">{{ target.counts.unused }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Given to buyers</dt>
                        <dd class="text-xl font-semibold tabular-nums">{{ target.counts.issued }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Withdrawn</dt>
                        <dd class="text-xl font-semibold tabular-nums">{{ target.counts.withdrawn }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Orders waiting</dt>
                        <dd class="text-xl font-semibold tabular-nums" :class="target.counts.waiting > 0 ? 'text-destructive' : ''">
                            {{ target.counts.waiting }}
                        </dd>
                    </div>
                </dl>
                <p v-if="target.counts.waiting > 0" class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
                    {{ target.counts.waiting }} {{ target.counts.waiting === 1 ? 'key is' : 'keys are' }} owed to customers who have paid. Add keys
                    and they are issued straight away, oldest order first.
                </p>
                <p class="text-xs text-muted-foreground">
                    In the shop: <span class="font-medium tabular-nums">{{ Math.max(0, target.quantity) }}</span> available. That is the unused keys
                    less the ones that orders are holding: those waiting for payment, and paid orders still waiting for a key.
                </p>

                <form v-if="can.create" class="space-y-2" @submit.prevent="add(target)">
                    <label :for="`keys-${target.key}`" class="text-sm font-medium">Add keys</label>
                    <Textarea
                        :id="`keys-${target.key}`"
                        :model-value="drafts[target.key] ?? ''"
                        rows="5"
                        class="font-mono text-sm"
                        placeholder="One key on each line"
                        spellcheck="false"
                        autocomplete="off"
                        :aria-invalid="!!problems[target.key] || undefined"
                        @update:model-value="(value) => (drafts[target.key] = String(value))"
                    />
                    <InputError :message="problems[target.key]" />
                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="submit" size="sm" :disabled="busy === target.key || lines(target) === 0">
                            Add {{ lines(target) || '' }} {{ lines(target) === 1 ? 'key' : 'keys' }}
                        </Button>
                        <span class="text-xs text-muted-foreground">
                            Up to {{ rules.max_per_upload }} at a time, {{ rules.max_length }} characters each. Repeats are skipped.
                        </span>
                    </div>
                </form>

                <div v-if="target.unused.length" class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-medium">
                            Newest unused keys
                            <span v-if="target.counts.unused > target.unused.length" class="font-normal text-muted-foreground">
                                (showing {{ target.unused.length }} of {{ target.counts.unused }})
                            </span>
                        </h4>
                        <Button
                            v-if="can.delete && (selected[target.key] ?? []).length"
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            @click="deleting = target"
                        >
                            <Trash2 class="size-4" /> Delete {{ (selected[target.key] ?? []).length }}
                        </Button>
                    </div>
                    <ul class="divide-y rounded-lg border text-sm">
                        <li v-for="key in target.unused" :key="key.id" class="flex items-center gap-3 px-3 py-2">
                            <Checkbox
                                v-if="can.delete"
                                :id="`key-${key.id}`"
                                :model-value="isSelected(target, key.id)"
                                :aria-label="`Select key ending ${key.masked.slice(-4)}`"
                                @update:model-value="(on) => toggle(target, key.id, on)"
                            />
                            <label :for="`key-${key.id}`" class="font-mono">{{ key.masked }}</label>
                            <span v-if="key.added_at" class="ml-auto text-xs text-muted-foreground">added {{ formatDateTime(key.added_at) }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="can.edit" class="flex flex-wrap items-center gap-3 border-t pt-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        :disabled="target.counts.unused > 0 || target.counts.waiting > 0"
                        @click="disableOpen = target"
                    >
                        Stop selling licence keys
                    </Button>
                    <span v-if="target.counts.unused > 0 || target.counts.waiting > 0" class="text-xs text-muted-foreground">
                        Delete the unused keys first (and issue any that are owed), so none are lost.
                    </span>
                </div>
            </template>
        </section>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deletingCount} unused ${deletingCount === 1 ? 'key' : 'keys'}?`"
            description="They are deleted for good: keys cannot be recovered or typed back in from here. Keys already given to customers are not affected."
            confirm-label="Delete keys"
            @update:open="(value) => !value && (deleting = null)"
            @confirm="remove"
            @cancel="deleting = null"
        />

        <ConfirmDialog
            :open="disableOpen !== null"
            title="Stop selling licence keys?"
            description="Stock here goes back to being counted by hand, starting from zero. Keys already given to customers stay with their orders."
            confirm-label="Stop selling keys"
            @update:open="(value) => !value && (disableOpen = null)"
            @confirm="disable"
            @cancel="disableOpen = null"
        />
    </div>
</template>
