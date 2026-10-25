<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import { discordUserApi } from '@/lib/discord-api';
import { type BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Discord notifications',
        href: '/settings/discord-notifications',
    },
];

const categories = ref<Record<string, string>>({});
const preferences = ref<Record<string, boolean>>({});
const loading = ref(true);
const saving = ref(false);

const entries = computed(() =>
    Object.entries(categories.value).map(([key, label]) => ({
        key,
        label,
        enabled: preferences.value[key] ?? false,
    })),
);

const load = async () => {
    loading.value = true;

    try {
        const response = await discordUserApi.notificationPreferences();
        categories.value = response.categories;
        preferences.value = { ...response.preferences };
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to load Discord notification settings.');
    } finally {
        loading.value = false;
    }
};

const save = async () => {
    saving.value = true;

    try {
        const response = await discordUserApi.updateNotificationPreferences(preferences.value);
        preferences.value = { ...response.preferences };
        toast.success('Discord notification preferences saved.');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to save preferences.');
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Discord notifications" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Discord notifications"
                    description="Opt in to Discord alerts when your account is linked via Discord OAuth. Delivery uses the companion bot webhook channel."
                />

                <Card v-if="loading">
                    <CardContent class="py-6 text-sm text-muted-foreground">Loading preferences…</CardContent>
                </Card>

                <form v-else class="space-y-4" @submit.prevent="save">
                    <Card v-for="entry in entries" :key="entry.key">
                        <CardHeader class="pb-3">
                            <CardTitle class="text-base">{{ entry.label }}</CardTitle>
                            <CardDescription>Off by default until you enable this category.</CardDescription>
                        </CardHeader>
                        <CardContent class="flex items-center justify-between gap-4">
                            <span class="text-sm text-muted-foreground">{{ entry.key }}</span>
                            <Switch
                                :checked="preferences[entry.key] ?? false"
                                @update:checked="(value: boolean) => (preferences[entry.key] = value)"
                            />
                        </CardContent>
                    </Card>

                    <Button type="submit" :disabled="saving">Save preferences</Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
