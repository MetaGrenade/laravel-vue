<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { toast } from 'vue-sonner';
import { usePermissions } from '@/composables/usePermissions';

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
        website_sections: Record<'blog' | 'forum' | 'support' | 'commerce', boolean>;
        oauth_providers: Record<string, boolean>;
    };
    oauthProviders: Array<{ key: string; label: string; description?: string | null; enabled: boolean }>;
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
        commerce: props.settings.website_sections.commerce,
    },
    oauth_providers: { ...props.settings.oauth_providers },
});

const diagnostics = computed(() => props.diagnostics);
const oauthProviderOptions = computed(() => props.oauthProviders ?? []);
const { hasPermission } = usePermissions();
const canEditSystemSettings = computed(() => hasPermission('system.acp.edit'));

const saveSettings = () => {
    if (!canEditSystemSettings.value) {
        return;
    }

    form.put(route('acp.system.update'), {
        preserveScroll: true,
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
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h3 class="mb-2 text-lg font-semibold">Maintenance Mode</h3>
                        <p class="mb-4 text-sm text-muted-foreground">Toggle maintenance mode to temporarily disable access for users.</p>
                        <div class="flex items-center">
                            <Switch v-model="form.maintenance_mode" :disabled="!canEditSystemSettings" />
                            <span class="ml-2 text-sm">
                                {{ form.maintenance_mode ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                    </div>

                    <!-- Email Verification -->
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h3 class="mb-2 text-lg font-semibold">Email Verification</h3>
                        <p class="mb-4 text-sm text-muted-foreground">Require users to verify their email address upon registration.</p>
                        <div class="flex items-center">
                            <Switch v-model="form.email_verification_required" :disabled="!canEditSystemSettings" />
                            <span class="ml-2 text-sm">
                                {{ form.email_verification_required ? 'Required' : 'Not Required' }}
                            </span>
                        </div>
                    </div>

                    <!-- Website Sections -->
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h3 class="mb-2 text-lg font-semibold">Website Sections</h3>
                        <p class="mb-4 text-sm text-muted-foreground">Enable or disable different sections of the website.</p>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Blog</p>
                                    <p class="text-xs text-muted-foreground">Control access to public blog content.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.blog" :disabled="!canEditSystemSettings" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.blog ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Forum</p>
                                    <p class="text-xs text-muted-foreground">Toggle the community forum for discussions.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.forum" :disabled="!canEditSystemSettings" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.forum ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Support</p>
                                    <p class="text-xs text-muted-foreground">Expose FAQs and ticket submission tools.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.support" :disabled="!canEditSystemSettings" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.support ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">Commerce</p>
                                    <p class="text-xs text-muted-foreground">Control access to the shop, cart, and orders.</p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.website_sections.commerce" :disabled="!canEditSystemSettings" />
                                    <span class="ml-2 text-sm">
                                        {{ form.website_sections.commerce ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- OAuth Providers -->
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h3 class="mb-2 text-lg font-semibold">OAuth Providers</h3>
                        <p class="mb-4 text-sm text-muted-foreground">
                            Control which social authentication providers are available for login and account linking.
                        </p>
                        <div v-if="oauthProviderOptions.length" class="space-y-3">
                            <div v-for="provider in oauthProviderOptions" :key="provider.key" class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium">{{ provider.label }}</p>
                                    <p v-if="provider.description" class="text-xs text-muted-foreground">
                                        {{ provider.description }}
                                    </p>
                                </div>
                                <div class="flex items-center">
                                    <Switch v-model="form.oauth_providers[provider.key]" :disabled="!canEditSystemSettings" />
                                    <span class="ml-2 text-sm">
                                        {{ form.oauth_providers[provider.key] ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="rounded border border-dashed border-sidebar-border/70 p-4 text-sm text-muted-foreground">
                            No OAuth providers are registered. Configure provider credentials and refresh the page.
                        </div>
                    </div>
                </div>

                <!-- System Information Section -->
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 class="mb-2 text-lg font-semibold">System Information</h3>
                    <ul class="space-y-2 text-sm">
                        <li>
                            <span class="font-medium text-muted-foreground">PHP Version: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.php_version }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Laravel Version: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.laravel_version }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Server Environment: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.server_environment }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Server Time: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.server_time }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Server Timezone: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.server_timezone }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Application URL: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.app_url ?? 'Not configured' }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Queue Connection: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.queue_connection }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Cache Driver: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.cache_driver }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Session Driver: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.session_driver }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Memory Usage: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.memory_usage }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-muted-foreground">Peak Memory Usage: </span>
                            <span class="font-medium text-muted-foreground">{{ diagnostics.memory_peak }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Save Settings Button -->
                <div v-if="canEditSystemSettings" class="flex justify-end">
                    <Button
                        @click="saveSettings"
                        :disabled="form.processing"
                        class="inline-flex h-9 items-center rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        Save Changes
                    </Button>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
