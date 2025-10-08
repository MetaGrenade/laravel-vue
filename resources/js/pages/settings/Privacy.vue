<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import type { BreadcrumbItem } from '@/types';

interface DataExportResource {
    id: number;
    status: string;
    format: string;
    failure_reason?: string | null;
    created_at?: string | null;
    completed_at?: string | null;
    download_url?: string | null;
}

interface ErasureRequestResource {
    id: number;
    status: string;
    created_at?: string | null;
    processed_at?: string | null;
}

interface Props {
    exports: DataExportResource[];
    erasureRequest: ErasureRequestResource | null;
    status?: string;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Privacy settings',
        href: '/settings/privacy',
    },
];

const exportForm = useForm({});
const erasureForm = useForm({});

const exportItems = computed(() => props.exports);
const erasureRequest = computed(() => props.erasureRequest);

const hasPendingExport = computed(() =>
    exportItems.value.some(exportItem => ['pending', 'processing'].includes(exportItem.status)),
);

const erasurePending = computed(() =>
    erasureRequest.value ? ['pending', 'processing'].includes(erasureRequest.value.status) : false,
);

const statusAlert = computed(() => {
    switch (props.status) {
        case 'export-requested':
            return {
                title: 'Export requested',
                description:
                    'We are preparing your archive. You will receive an email when it is ready to download.',
            };
        case 'erasure-requested':
            return {
                title: 'Erasure request submitted',
                description:
                    'Our trust & safety team has been notified. We will confirm completion within 30 days.',
            };
        case 'export-pending':
            return {
                title: 'Export already in progress',
                description: 'Please wait for your current export to finish before requesting another.',
            };
        case 'erasure-pending':
            return {
                title: 'Pending erasure request',
                description:
                    'You have an active erasure request. We will reach out via email if we need more information.',
            };
        default:
            return null;
    }
});

const exportError = computed(() => exportForm.errors.export ?? null);
const erasureError = computed(() => erasureForm.errors.erasure ?? null);

const requestExport = () => {
    if (!exportForm.processing) {
        exportForm.post(route('privacy.exports.store'), {
            preserveScroll: true,
        });
    }
};

const requestErasure = () => {
    if (!erasureForm.processing) {
        erasureForm.post(route('privacy.erasure.store'), {
            preserveScroll: true,
        });
    }
};

const statusLabel = (status: string) => {
    switch (status) {
        case 'pending':
            return 'Pending';
        case 'processing':
            return 'Processing';
        case 'completed':
            return 'Ready to download';
        case 'failed':
            return 'Failed';
        default:
            return status;
    }
};

const formatDateTime = (value?: string | null) => {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Privacy settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="space-y-4">
                    <HeadingSmall
                        title="Data export"
                        description="Request a portable copy of your content and account history."
                    />

                    <Alert v-if="statusAlert" variant="warning">
                        <AlertTitle>{{ statusAlert.title }}</AlertTitle>
                        <AlertDescription>{{ statusAlert.description }}</AlertDescription>
                    </Alert>

                    <p class="text-sm text-muted-foreground">
                        We bundle your profile, forum posts, support interactions, and other contributions into a
                        downloadable archive. Exports are available for 30 minutes once ready and can only be accessed
                        through a signed download link.
                    </p>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-foreground">Download my data</p>
                            <p class="text-sm text-muted-foreground">
                                You may request one export at a time. We typically fulfill requests within a few minutes.
                            </p>
                        </div>

                        <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center">
                            <Button
                                type="button"
                                :disabled="exportForm.processing || hasPendingExport"
                                @click="requestExport"
                            >
                                <span v-if="exportForm.processing">Requesting…</span>
                                <span v-else-if="hasPendingExport">Export in progress</span>
                                <span v-else>Download my data</span>
                            </Button>
                            <p v-if="exportError" class="text-sm text-destructive">{{ exportError }}</p>
                        </div>
                    </div>

                    <div v-if="exportItems.length" class="rounded-lg border">
                        <div
                            v-for="(exportItem, index) in exportItems"
                            :key="exportItem.id"
                            :class="[
                                'grid gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center',
                                index !== exportItems.length - 1 ? 'border-b' : '',
                            ]"
                        >
                            <div class="space-y-2">
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
                                    <p class="text-sm font-medium text-foreground">
                                        Requested {{ formatDateTime(exportItem.created_at) }}
                                    </p>
                                    <span
                                        class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium"
                                        :class="{
                                            'border-yellow-500 text-yellow-600 dark:text-yellow-400':
                                                ['pending', 'processing'].includes(exportItem.status),
                                            'border-green-500 text-green-600 dark:text-green-400':
                                                exportItem.status === 'completed',
                                            'border-destructive text-destructive': exportItem.status === 'failed',
                                        }"
                                    >
                                        {{ statusLabel(exportItem.status) }}
                                    </span>
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    <span v-if="exportItem.completed_at">
                                        Ready since {{ formatDateTime(exportItem.completed_at) }}.
                                    </span>
                                    <span v-else-if="exportItem.status === 'failed' && exportItem.failure_reason">
                                        {{ exportItem.failure_reason }}
                                    </span>
                                    <span v-else>
                                        We will email you when this export is ready.
                                    </span>
                                </p>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    v-if="exportItem.download_url"
                                    variant="outline"
                                    as-child
                                >
                                    <a :href="exportItem.download_url">Download archive</a>
                                </Button>
                                <span v-else class="text-sm text-muted-foreground">Not available yet</span>
                            </div>
                        </div>
                    </div>

                    <p v-else class="text-sm text-muted-foreground">
                        You have not requested any exports yet.
                    </p>
                </div>

                <div class="space-y-4">
                    <HeadingSmall
                        title="Data erasure"
                        description="Ask us to remove your personal information from our systems."
                    />

                    <p class="text-sm text-muted-foreground">
                        We review every request to ensure we retain only the minimum data required by law and our
                        contractual obligations. Once processed, removal is permanent and cannot be undone.
                    </p>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-foreground">Request erasure</p>
                            <p class="text-sm text-muted-foreground">
                                Submitting a request will deactivate your account while our compliance team completes the
                                review.
                            </p>
                        </div>

                        <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center">
                            <Button
                                type="button"
                                variant="destructive"
                                :disabled="erasureForm.processing || erasurePending"
                                @click="requestErasure"
                            >
                                <span v-if="erasureForm.processing">Submitting…</span>
                                <span v-else-if="erasurePending">Request pending</span>
                                <span v-else>Request erasure</span>
                            </Button>
                            <p v-if="erasureError" class="text-sm text-destructive">{{ erasureError }}</p>
                        </div>
                    </div>

                    <div v-if="erasureRequest" class="rounded-lg border p-4">
                        <p class="text-sm font-medium text-foreground">
                            Latest request submitted {{ formatDateTime(erasureRequest.created_at) }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Status: {{ statusLabel(erasureRequest.status) }}
                            <span v-if="erasureRequest.processed_at">
                                — completed on {{ formatDateTime(erasureRequest.processed_at) }}
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
