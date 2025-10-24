<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Toaster, toast } from 'vue-sonner';


const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'System Settings ACP',
        href: '/acp/system',
    },
];

const props = defineProps<{
    settings: {
        maintenance_mode: boolean;
        email_verification_required: boolean;
        website_sections: Record<'blog' | 'forum' | 'support', boolean>;
    };
    diagnostics: {
        php_version: string;
        laravel_version: string;
        server_environment: string;
        server_time: string;
        server_timezone: string;
        app_url: string | null;
        queue_connection: string | null;
        cache_driver: string | null;
        session_driver: string | null;
        memory_usage: string;
        memory_peak: string;
    };
}>();

const form = useForm({
    maintenance_mode: props.settings.maintenance_mode,
    email_verification_required: props.settings.email_verification_required,
    website_sections: {
        blog: props.settings.website_sections.blog,
        forum: props.settings.website_sections.forum,
        support: props.settings.website_sections.support,
    },
});

const diagnostics = computed(() => props.diagnostics);

const saveSettings = () => {
    form.put(route('acp.system.update'), {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('System settings saved successfully');
        },
        onError: () => {
            toast.error('Unable to save settings. Please review the form and try again.');
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="System Settings" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Settings Controls Section -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <!-- Maintenance Mode -->
                    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                        <h3 class="mb-2 text-lg font-semibold">Maintenance Mode</h3>
                        <p class="mb-4 text-sm text-gray-500">
                            Toggle maintenance mode to temporarily disable access for users.
                        </p>
                        <div class="flex items-center">
                            <Switch v-model="form.maintenance_mode" />
                            <span class="ml-2 text-sm">
                                {{ form.maintenance_mode ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                    </div>

                    <!-- Email Verification -->
                    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                        <h3 class="mb-2 text-lg font-semibold">Email Verification</h3>
                        <p class="mb-4 text-sm text-gray-500">
                            Require users to verify their email address upon registration.
                        </p>
                        <div class="flex items-center">
                            <Switch v-model="form.email_verification_required" />
                            <span class="ml-2 text-sm">
                                {{ form.email_verification_required ? 'Required' : 'Not Required' }}
                            </span>
                        </div>
                    </div>

                    <!-- Website Sections -->
                    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                        <h3 class="mb-2 text-lg font-semibold">Website Sections</h3>
                        <p class="mb-4 text-sm text-gray-500">
                            Enable or disable different sections of the website.
                        </p>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Blog</p>
                                    <p class="text-xs text-gray-500">Control access to public blog content.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.blog" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.blog ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Forum</p>
                                    <p class="text-xs text-gray-500">Toggle the community forum for discussions.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.forum" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.forum ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Support</p>
                                    <p class="text-xs text-gray-500">Expose FAQs and ticket submission tools.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.support" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.support ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Information Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <h3 class="mb-2 text-lg font-semibold">System Information</h3>
                    <ul class="space-y-2 text-sm">
                        <li>
                            <span class="font-medium text-gray-500">PHP Version: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.php_version }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Laravel Version: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.laravel_version }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Server Environment: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.server_environment }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Server Time: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.server_time }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Server Timezone: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.server_timezone }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Application URL: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.app_url ?? 'Not configured' }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Queue Connection: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.queue_connection }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Cache Driver: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.cache_driver }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Session Driver: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.session_driver }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Memory Usage: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.memory_usage }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Peak Memory Usage: </span>
                            <span class="font-medium text-gray-600">{{ diagnostics.memory_peak }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Save Settings Button -->
                <div class="flex justify-end">
                    <Toaster theme="dark" richColors />
                    <Button
                        @click="saveSettings"
                        :disabled="form.processing"
                        class="rounded bg-blue-500 px-6 py-2 text-white hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        Save Changes
                    </Button>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
