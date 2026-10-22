<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

interface WebhookUser {
    id: number;
    nickname: string;
    email: string;
}

interface WebhookCallDetail {
    id: number;
    stripe_id: string | null;
    /** Who sent it: "stripe" or "tebex". */
    provider: string | null;
    /** The sender's id for the delivery. */
    external_id: string | null;
    attempts: number;
    /** Why it needs a look: understood but not acted on, or failed and waiting to be sent again. */
    error: string | null;
    type: string;
    user: WebhookUser | null;
    processed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    payload: Record<string, unknown> | null;
}

interface Props {
    call: WebhookCallDetail;
    can: { replay: boolean };
}

const props = defineProps<Props>();

const deliveryId = computed(() => props.call.external_id ?? props.call.stripe_id);

const providerName = computed(() =>
    props.call.provider === 'tebex' ? 'Tebex' : props.call.provider === 'stripe' ? 'Stripe' : (props.call.provider ?? 'The sender'),
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing webhooks', href: route('acp.billing.webhooks.index') },
    {
        title: deliveryId.value ?? `Webhook #${props.call.id}`,
        href: route('acp.billing.webhooks.show', props.call.id),
    },
];

const pageTitle = computed(() => (deliveryId.value ? `Webhook ${deliveryId.value}` : `Webhook #${props.call.id}`));

const replaying = ref(false);

const formatDateTime = (value: string | null) => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
};

const payloadPreview = computed(() => {
    if (!props.call.payload) {
        return 'No payload captured for this webhook call.';
    }

    try {
        return JSON.stringify(props.call.payload, null, 2);
    } catch {
        return String(props.call.payload);
    }
});

const replayWebhook = () => {
    if (replaying.value) {
        return;
    }

    replaying.value = true;

    router.post(
        route('acp.billing.webhooks.replay', props.call.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                replaying.value = false;
            },
        },
    );
};

const copyStatus = ref<'idle' | 'copied' | 'failed'>('idle');
let copyResetHandle: ReturnType<typeof setTimeout> | null = null;

const copyPayload = async () => {
    if (!props.call.payload) {
        return;
    }

    if (copyResetHandle) {
        clearTimeout(copyResetHandle);
        copyResetHandle = null;
    }

    if (typeof navigator === 'undefined' || !('clipboard' in navigator)) {
        copyStatus.value = 'failed';
        return;
    }

    try {
        await navigator.clipboard.writeText(payloadPreview.value);
        copyStatus.value = 'copied';
    } catch {
        copyStatus.value = 'failed';
    }

    copyResetHandle = setTimeout(() => {
        copyStatus.value = 'idle';
        copyResetHandle = null;
    }, 2000);
};

onBeforeUnmount(() => {
    if (copyResetHandle) {
        clearTimeout(copyResetHandle);
        copyResetHandle = null;
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="pageTitle" />

        <AdminLayout>
            <section class="flex w-full flex-col space-y-6">
                <HeadingSmall :title="pageTitle" description="Inspect the payload and metadata kept when the webhook was received." />

                <div v-if="props.call.error" class="rounded-lg border border-warning/50 bg-warning/10 p-4 text-sm" role="alert">
                    <p class="font-medium">This delivery needs a look</p>
                    <p class="mt-1 text-muted-foreground">{{ props.call.error }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('acp.billing.webhooks.index')">Back to archive</Link>
                    </Button>
                    <Button v-if="props.can.replay" :disabled="replaying" @click="replayWebhook">
                        <span v-if="replaying">Replaying…</span>
                        <span v-else>Replay webhook</span>
                    </Button>
                    <Button v-if="props.call.payload" type="button" variant="ghost" @click="copyPayload">
                        <span v-if="copyStatus === 'copied'">Copied!</span>
                        <span v-else-if="copyStatus === 'failed'">Copy failed</span>
                        <span v-else>Copy payload</span>
                    </Button>
                </div>

                <div class="rounded-lg border border-border bg-card p-6 shadow-xs">
                    <dl class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">{{ providerName }} delivery ID</dt>
                            <dd class="font-mono text-sm">{{ deliveryId ?? '—' }}</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">Attempts</dt>
                            <dd class="text-sm">{{ props.call.attempts }}</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">Webhook type</dt>
                            <dd class="text-sm">{{ props.call.type }}</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">Associated user</dt>
                            <dd class="text-sm">
                                <template v-if="props.call.user">
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ props.call.user.nickname }}</span>
                                        <span class="text-xs text-muted-foreground">{{ props.call.user.email }}</span>
                                    </div>
                                </template>
                                <template v-else>
                                    <span class="text-xs text-muted-foreground">Unlinked</span>
                                </template>
                            </dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">Recorded at</dt>
                            <dd class="text-sm">{{ formatDateTime(props.call.created_at) }}</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">Processed at</dt>
                            <dd class="text-sm">
                                <span v-if="props.call.processed_at">{{ formatDateTime(props.call.processed_at) }}</span>
                                <span v-else class="text-xs font-medium tracking-wide text-warning uppercase">Pending</span>
                            </dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-sm font-medium text-muted-foreground">Last updated</dt>
                            <dd class="text-sm">{{ formatDateTime(props.call.updated_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-lg border border-border bg-card p-6 shadow-xs">
                    <h2 class="mb-4 text-sm font-semibold tracking-wide text-muted-foreground uppercase">Payload</h2>
                    <pre class="max-h-[600px] overflow-auto rounded-md bg-muted p-4 text-xs leading-relaxed">{{ payloadPreview }}</pre>
                </div>
            </section>
        </AdminLayout>
    </AppLayout>
</template>
