<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Props {
    channelPreferences: Record<'mail' | 'push' | 'database', boolean>;
    emailIsVerified: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Notification settings',
        href: '/settings/notifications',
    },
];

const form = useForm({
    channels: {
        mail: props.channelPreferences.mail,
        push: props.channelPreferences.push,
        database: props.channelPreferences.database,
    },
});

const submit = () => {
    form.put(route('notifications.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Notification settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Support ticket notifications"
                    description="Choose how we should notify you when something changes in your support conversations."
                />

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="space-y-4">
                        <div class="flex items-start justify-between space-x-4 rounded-lg border p-4">
                            <div class="space-y-1">
                                <Label class="text-sm font-medium">Email</Label>
                                <p class="text-sm text-muted-foreground">
                                    Receive updates in your inbox when a ticket is opened, replied to, or updated.
                                </p>
                                <p v-if="!props.emailIsVerified" class="text-xs text-muted-foreground">
                                    Verify your email address to enable email notifications.
                                </p>
                                <InputError :message="form.errors['channels.mail']" />
                            </div>
                            <Switch
                                v-model:checked="form.channels.mail"
                                :disabled="!props.emailIsVerified"
                                aria-label="Toggle email notifications"
                            />
                        </div>

                        <div class="flex items-start justify-between space-x-4 rounded-lg border p-4">
                            <div class="space-y-1">
                                <Label class="text-sm font-medium">Push notifications</Label>
                                <p class="text-sm text-muted-foreground">
                                    Get realtime alerts in the browser when a ticket needs your attention.
                                </p>
                                <InputError :message="form.errors['channels.push']" />
                            </div>
                            <Switch
                                v-model:checked="form.channels.push"
                                aria-label="Toggle push notifications"
                            />
                        </div>

                        <div class="flex items-start justify-between space-x-4 rounded-lg border p-4">
                            <div class="space-y-1">
                                <Label class="text-sm font-medium">In-app notifications</Label>
                                <p class="text-sm text-muted-foreground">
                                    Show updates in your notifications menu so you can review them later.
                                </p>
                                <InputError :message="form.errors['channels.database']" />
                            </div>
                            <Switch
                                v-model:checked="form.channels.database"
                                aria-label="Toggle in-app notifications"
                            />
                        </div>
                    </div>

                    <InputError :message="form.errors.channels" />

                    <div class="flex items-center justify-end space-x-2">
                        <Button type="submit" :disabled="form.processing">
                            Save changes
                        </Button>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
