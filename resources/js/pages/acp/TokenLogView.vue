<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import { ArrowLeft } from 'lucide-vue-next';
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
const relativeTimestamp = computed(() => log.value.timestamp ? fromNow(log.value.timestamp) : 'Unknown');

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
    } catch (error) {
        return '—';
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Token Log Detail" />
        <AdminLayout>
            <div class="container mx-auto p-4 space-y-8">
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
                <div class="rounded-xl border p-6 shadow-sm">
                    <h2 class="mb-4 text-xl font-semibold">Log Information</h2>
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">ID</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.id }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Token Name</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.token_name ?? 'Unknown token' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">API Route</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.api_route }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">HTTP Method</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.method }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.status }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">HTTP Status Code</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.http_status ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Timestamp</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.timestamp ?? 'Unknown' }}</dd>
                            <dd v-if="log.timestamp" class="text-sm text-muted-foreground">{{ relativeTimestamp }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">IP Address</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.ip ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Response Time</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.response_time_ms ? `${log.response_time_ms} ms` : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Request Payload</dt>
                            <dd class="text-sm font-mono whitespace-pre-wrap break-words bg-muted/40 p-3 rounded">{{ formattedRequestPayload }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Response Summary</dt>
                            <dd class="text-sm font-mono whitespace-pre-wrap break-words bg-muted/40 p-3 rounded">{{ formattedResponseSummary }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">User Agent</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ log.user_agent ?? '—' }}</dd>
                        </div>
                        <div v-if="log.error_message">
                            <dt class="text-sm font-medium text-red-500">Error Message</dt>
                            <dd class="text-lg font-bold text-red-500">{{ log.error_message }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
