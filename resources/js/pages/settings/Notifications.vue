<script setup lang="ts">
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import { type BreadcrumbItem } from '@/types';

type ChannelPreference = {
    key: string;
    label: string;
    description?: string | null;
    enabled: boolean;
};

type CategoryPreference = {
    key: string;
    label: string;
    description?: string | null;
    channels: ChannelPreference[];
};

interface Props {
    categories: CategoryPreference[];
    status?: string | null;
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Notification settings',
        href: '/settings/notifications',
    },
];

const initialPreferences = computed<Record<string, Record<string, boolean>>>(() => {
    return props.categories.reduce<Record<string, Record<string, boolean>>>((acc, category) => {
        acc[category.key] = category.channels.reduce<Record<string, boolean>>((channelsAcc, channel) => {
            channelsAcc[channel.key] = channel.enabled;

            return channelsAcc;
        }, {});

        return acc;
    }, {});
});

const clonePreferences = (preferences: Record<string, Record<string, boolean>>) =>
    JSON.parse(JSON.stringify(preferences)) as Record<string, Record<string, boolean>>;

const form = useForm<{ preferences: Record<string, Record<string, boolean>> }>({
    preferences: clonePreferences(initialPreferences.value),
});

watch(
    initialPreferences,
    (preferences) => {
        const snapshot = clonePreferences(preferences);

        form.defaults({ preferences: clonePreferences(preferences) });
        form.setData('preferences', snapshot);
    },
    { immediate: true },
);

const submit = () => {
    form.put(route('settings.notifications.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Notification settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Notification preferences"
                    description="Choose how you would like to hear from us across each area of the community."
                />

                <div
                    v-if="props.status"
                    class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                >
                    {{ props.status }}
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="grid gap-4">
                        <Card v-for="category in props.categories" :key="category.key">
                            <CardHeader>
                                <CardTitle>{{ category.label }}</CardTitle>
                                <CardDescription v-if="category.description">
                                    {{ category.description }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div
                                    v-for="channel in category.channels"
                                    :key="`${category.key}-${channel.key}`"
                                    class="flex flex-col gap-2 rounded-md border border-border/60 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p class="font-medium">{{ channel.label }}</p>
                                        <p v-if="channel.description" class="text-sm text-muted-foreground">
                                            {{ channel.description }}
                                        </p>
                                    </div>
                                    <Switch
                                        v-model="form.preferences[category.key][channel.key]"
                                        :aria-label="`Toggle ${channel.label} notifications for ${category.label}`"
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <div class="flex justify-end">
                        <Button type="submit" :disabled="form.processing">
                            Save changes
                        </Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
