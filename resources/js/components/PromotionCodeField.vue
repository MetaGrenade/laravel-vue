<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CircleAlert, TicketPercent, X } from '@lucide/vue';
import { ref, watch } from 'vue';

/**
 * Where a customer types a promotion code for a subscription, and is told at once what it takes off or why it cannot be used.
 *
 * The code is checked against the chosen plan before any payment is made, but what is sent when the subscription starts is the
 * typed code (the server looks it up again), so a code that was typed and never applied still counts, and is refused there if it
 * will not do. Not a <form>: it sits in pages that have their own button for starting the subscription.
 */
interface Offer {
    code: string;
    summary: string;
    notes: string[];
}

const props = withDefaults(
    defineProps<{
        /** The plan the code is for; what a code takes off depends on it. */
        planId: number | null;
        /** Where to ask whether a code can be used. */
        checkUrl: string;
        id?: string;
    }>(),
    { id: 'promotion-code' },
);

/** The code as typed. */
const code = defineModel<string>({ default: '' });

const offer = ref<Offer | null>(null);
const checkedFor = ref('');
const error = ref<string | null>(null);
const checking = ref(false);

/**
 * Which check is the current one. Changing the code or the plan while one is on its way makes it out of date, and its answer is
 * dropped when it arrives: it is about something else by then.
 */
let current = 0;

const apply = async () => {
    const typed = code.value.trim();

    if (checking.value || typed === '' || props.planId === null) {
        return;
    }

    const check = ++current;

    checking.value = true;
    error.value = null;
    offer.value = null;

    try {
        const response = await fetch(props.checkUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            body: JSON.stringify({ plan_id: props.planId, promotion_code: typed }),
        });

        const payload = await response.json().catch(() => ({}));

        if (check !== current) {
            return;
        }

        if (response.ok) {
            offer.value = payload as Offer;
            checkedFor.value = typed;
            return;
        }

        error.value =
            response.status === 429
                ? 'Too many tries. Please wait a minute and try again.'
                : (payload?.errors?.promotion_code?.[0] ?? payload?.message ?? "We couldn't check that code just now.");
    } catch {
        if (check === current) {
            error.value = "We couldn't check that code just now.";
        }
    } finally {
        if (check === current) {
            checking.value = false;
        }
    }
};

// What was being asked is not wanted any more: its answer is dropped, and the box is free for the next question.
const withdraw = () => {
    current++;
    checking.value = false;
    error.value = null;
};

// Changing the code (or clearing it after a subscription was started) withdraws what was said about the old one.
watch(code, (typed) => {
    withdraw();

    if (offer.value && typed.trim() !== checkedFor.value) {
        offer.value = null;
    }
});

// What a code takes off depends on the plan, so a code that was fine for one is checked again for the next, and so is one that was
// still being checked.
watch(
    () => props.planId,
    () => {
        const wasAsked = offer.value !== null || checking.value;

        withdraw();
        offer.value = null;

        if (wasAsked) {
            void apply();
        }
    },
);
</script>

<template>
    <div class="space-y-2">
        <div v-if="offer" class="space-y-1" role="status">
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-primary/40 bg-primary/5 px-3 py-2 text-sm">
                <span class="flex min-w-0 items-center gap-2">
                    <TicketPercent class="size-4 shrink-0 text-primary" aria-hidden="true" />
                    <span class="font-mono font-medium break-all">{{ offer.code }}</span>
                    <span class="text-muted-foreground">{{ offer.summary }}</span>
                </span>
                <Button type="button" variant="ghost" size="sm" :aria-label="`Remove code ${offer.code}`" @click="code = ''">
                    <X class="size-4" /> Remove
                </Button>
            </div>
            <p v-for="note in offer.notes" :key="note" class="text-xs text-muted-foreground">{{ note }}</p>
        </div>

        <div v-else class="space-y-1">
            <label :for="id" class="text-sm font-medium">Promotion code (optional)</label>
            <div class="flex gap-2">
                <Input
                    :id="id"
                    v-model="code"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="64"
                    class="font-mono uppercase"
                    :aria-invalid="!!error || undefined"
                    :aria-describedby="error ? `${id}-error` : undefined"
                    @keydown.enter.prevent="apply"
                />
                <Button type="button" variant="outline" :disabled="checking || code.trim() === '' || planId === null" @click="apply">
                    <span v-if="checking">Checking…</span>
                    <span v-else>Apply</span>
                </Button>
            </div>
            <p v-if="error" :id="`${id}-error`" class="flex items-start gap-1.5 text-sm text-destructive" role="alert">
                <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ error }}
            </p>
        </div>
    </div>
</template>
