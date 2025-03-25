<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch'; // Import the switch component from shadcn-vue

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'System Settings',
        href: '/acp/system',
    },
];

// Reactive state for system settings
const maintenanceMode = ref(false);
const emailVerificationRequired = ref(true);
// const enabledSections = ref({
//     blog: true,
//     forum: true,
//     support: true,
// });

// Dummy system info (replace with dynamic data as needed)
const phpVersion = '8.4';
const laravelVersion = '12.x';
const serverEnvironment = 'Development';

// Additional server info
const serverTime = ref('2023-07-27 15:34:00'); // Example server time
const serverTimezone = ref('UTC');
const memoryUsage = ref('512 MB used / 2048 MB total');
const averagePageLoadSpeed = ref('0.69 s');

// Function to "save" settings (stubbed)
function saveSettings() {
    console.log('Saving settings...', {
        maintenanceMode: maintenanceMode.value,
        emailVerificationRequired: emailVerificationRequired.value,
        // enabledSections: enabledSections.value,
    });
    alert('Settings saved (dummy implementation)!');
}
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
                            <Switch v-model="maintenanceMode" />
                            <span class="ml-2 text-sm">
                {{ maintenanceMode ? 'Enabled' : 'Disabled' }}
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
                            <Switch v-model="emailVerificationRequired" />
                            <span class="ml-2 text-sm">
                {{ emailVerificationRequired ? 'Required' : 'Not Required' }}
              </span>
                        </div>
                    </div>

                    <!-- Optionally, if you want to update Website Sections using switches, uncomment below -->
                    <!--
                    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                      <h3 class="mb-2 text-lg font-semibold">Website Sections</h3>
                      <p class="mb-4 text-sm text-gray-500">
                        Enable or disable different sections of the website.
                      </p>
                      <div class="space-y-2">
                        <div class="flex items-center">
                          <Switch v-model="enabledSections.blog" />
                          <span class="ml-2 text-sm">Blog</span>
                        </div>
                        <div class="flex items-center">
                          <Switch v-model="enabledSections.forum" />
                          <span class="ml-2 text-sm">Forum</span>
                        </div>
                        <div class="flex items-center">
                          <Switch v-model="enabledSections.support" />
                          <span class="ml-2 text-sm">Support</span>
                        </div>
                      </div>
                    </div>
                    -->
                </div>

                <!-- System Information Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <h3 class="mb-2 text-lg font-semibold">System Information</h3>
                    <ul class="space-y-2 text-sm">
                        <li>
                            <span class="font-medium text-gray-500">PHP Version: </span>
                            <span class="font-medium text-gray-600">{{ phpVersion }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Laravel Version: </span>
                            <span class="font-medium text-gray-600">{{ laravelVersion }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Server Environment: </span>
                            <span class="font-medium text-gray-600">{{ serverEnvironment }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Server Time: </span>
                            <span class="font-medium text-gray-600">{{ serverTime }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Server Timezone: </span>
                            <span class="font-medium text-gray-600">{{ serverTimezone }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Memory Usage: </span>
                            <span class="font-medium text-gray-600">{{ memoryUsage }}</span>
                        </li>
                        <li>
                            <span class="font-medium text-gray-500">Average Page Load Speed: </span>
                            <span class="font-medium text-gray-600">{{ averagePageLoadSpeed }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Save Settings Button -->
                <div class="flex justify-end">
                    <button @click="saveSettings" class="rounded bg-blue-500 px-6 py-2 text-white hover:bg-blue-600">
                        Save Changes
                    </button>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
