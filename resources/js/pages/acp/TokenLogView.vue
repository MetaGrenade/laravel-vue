<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import { ArrowLeft } from 'lucide-vue-next';

// Define an interface for our token log details with additional fields
interface TokenLogDetail {
    id: number;
    token_name: string;
    api_route: string;
    method: string;
    status: string;
    http_status: number;
    timestamp: string;
    ip: string;
    response_time: number;
    request_params: string;      // Sanitized request parameters
    response_summary: string;    // Summary of the response data
    user_agent: string;          // Information about the client making the request
    server_memory_usage: string; // e.g., "512 MB / 2048 MB"
    auth_method: string;         // e.g., "session" or "token"
    error_message?: string;      // Additional error details (if any)
}

// Dummy breadcrumbs for navigation
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens', href: '/acp/tokens' },
    { title: 'Activity Logs', href: '/acp/tokens/logs' },
    { title: 'Log Detail', href: '#' },
];

// Dummy token log data with extra details
const tokenLog = ref<TokenLogDetail>({
    id: 1,
    token_name: 'Admin Token',
    api_route: '/api/dashboard',
    method: 'GET',
    status: 'success',
    http_status: 200,
    timestamp: '2023-07-28 07:45:00',
    ip: '192.168.1.10',
    response_time: 125,
    request_params: '{"id":1}', // Example: a sanitized JSON string
    response_summary: '{"data":"Dashboard data retrieved successfully"}', // Example: a sanitized JSON string
    user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)...',
    server_memory_usage: '512 MB / 2048 MB',
    auth_method: 'token',
    error_message: '{"error":"lorem ipsum"}', // Example: a sanitized JSON string
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Token Log Detail" />
        <AdminLayout>
            <div class="container mx-auto p-4 space-y-8">
                <!-- Back Button & Page Heading -->
                <div class="flex items-center space-x-4">
                    <Link :href="route('acp.tokens')">
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
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.id }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Token Name</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.token_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">API Route</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.api_route }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">HTTP Method</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.method }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.status }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">HTTP Status Code</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.http_status }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Timestamp</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.timestamp }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">IP Address</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.ip }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Response Time</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.response_time }} ms</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Request Parameters</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.request_params }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Response Summary</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.response_summary }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">User Agent</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.user_agent }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Server Memory Usage</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.server_memory_usage }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Authentication Method</dt>
                            <dd class="text-lg font-bold text-gray-700">{{ tokenLog.auth_method }}</dd>
                        </div>
                        <div v-if="tokenLog.error_message">
                            <dt class="text-sm font-medium text-red-500">Error Message</dt>
                            <dd class="text-lg font-bold text-red-500">{{ tokenLog.error_message }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
