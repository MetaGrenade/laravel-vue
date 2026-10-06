<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import { ArrowLeft } from '@lucide/vue';
import { useUserTimezone } from '@/composables/useUserTimezone';

interface TokenLogDetail {
    id: number;
    token_name: string | null;
    api_route: string;
    method: string;
    status: string;
    http_status: number | null;
    timestamp: string | null;
    ip: string | null;
    response_time_ms: number | null;
    request_payload: Record<string, unknown> | unknown[] | null;
    response_summary: Record<string, unknown> | unknown[] | null;
    user_agent: string | null;
    error_message?: string | null;
}

const props = defineProps<{ log: TokenLogDetail }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens', href: '/acp/tokens' },
    { title: 'Activity Logs', href: '/acp/tokens#logs' },
    { title: 'Log Detail', href: '#' },
];

const { fromNow } = useUserTimezone();

const log = computed(() => props.log);

const formattedRequestPayload = computed(() => formatStructuredData(log.value.request_payload));
const formattedResponseSummary = computed(() => formatStructuredData(log.value.response_summary));
const relativeTimestamp = computed(() => (log.value.timestamp ? fromNow(log.value.timestamp) : 'Unknown'));

function formatStructuredData(data: Record<string, unknown> | unknown[] | null): string {
    if (!data) {
        return '—';
    }

    if (Array.isArray(data)) {
        if (data.length === 0) {
            return '—';
        }
    } else if (Object.keys(data).length === 0) {
        return '—';
    }

    try {
        return JSON.stringify(data, null, 2);
    } catch {
        return '—';
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Token Log Detail" />
        <AdminLayout>
            <div class="container mx-auto space-y-8 p-4">
                <!-- Back Button & Page Heading -->
                <div class="flex items-center space-x-4">
                    <Link :href="route('acp.tokens.index')">
                        <Button variant="outline" size="icon">
                            <ArrowLeft class="h-5 w-5" />
                        </Button>
                    </Link>
                    <h1 class="text-3xl font-bold">Token Log Detail</h1>
                </div>

                <!-- Log Detail Card -->
                <div class="rounded-xl border p-6 shadow-xs">
                    <h2 class="mb-4 text-xl font-semibold">Log Information</h2>
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">ID</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.id }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Token Name</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.token_name ?? 'Unknown token' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">API Route</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.api_route }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">HTTP Method</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.method }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Status</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.status }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">HTTP Status Code</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.http_status ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Timestamp</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.timestamp ?? 'Unknown' }}</dd>
                            <dd v-if="log.timestamp" class="text-sm text-muted-foreground">{{ relativeTimestamp }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">IP Address</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.ip ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Response Time</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.response_time_ms ? `${log.response_time_ms} ms` : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Request Payload</dt>
                            <dd class="rounded bg-muted/40 p-3 font-mono text-sm wrap-break-word whitespace-pre-wrap">
                                {{ formattedRequestPayload }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">Response Summary</dt>
                            <dd class="rounded bg-muted/40 p-3 font-mono text-sm wrap-break-word whitespace-pre-wrap">
                                {{ formattedResponseSummary }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-muted-foreground">User Agent</dt>
                            <dd class="text-lg font-bold text-foreground/80">{{ log.user_agent ?? '—' }}</dd>
                        </div>
                        <div v-if="log.error_message">
                            <dt class="text-sm font-medium text-destructive">Error Message</dt>
                            <dd class="text-lg font-bold text-destructive">{{ log.error_message }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
