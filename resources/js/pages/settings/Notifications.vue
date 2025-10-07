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

type ChannelToggle = Record<'mail' | 'push' | 'database', boolean>;
type PreferenceKey = 'support_ticket' | 'forum_subscription' | 'blog_subscription';

interface Props {
    channelPreferences: Record<PreferenceKey, ChannelToggle>;
    emailIsVerified: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Notification settings',
        href: '/settings/notifications',
    },
];

const sections: Array<{
    key: PreferenceKey;
    title: string;
    description: string;
    copy: ChannelToggle;
}> = [
    {
        key: 'support_ticket',
        title: 'Support ticket notifications',
        description: 'Choose how we should notify you when something changes in your support conversations.',
        copy: {
            mail: 'Receive updates in your inbox when a ticket is opened, replied to, or updated.',
            push: 'Get realtime alerts in the browser when a ticket needs your attention.',
            database: 'Show updates in your notifications menu so you can review them later.',
        },
    },
    {
        key: 'forum_subscription',
        title: 'Forum subscription notifications',
        description: 'Control how you stay in the loop when new replies are posted in threads you follow.',
        copy: {
            mail: 'Receive an email when someone replies to a forum thread you are subscribed to.',
            push: 'Get realtime browser alerts whenever subscribed threads receive new replies.',
            database: 'Keep forum reply updates in your in-app notifications list for later review.',
        },
    },
    {
        key: 'blog_subscription',
        title: 'Blog subscription notifications',
        description: 'Decide how we should notify you about new comments on blog posts you are following.',
        copy: {
            mail: 'Get an email whenever a new comment is added to a blog post you subscribed to.',
            push: 'See realtime browser alerts for fresh blog comments as they arrive.',
            database: 'Store new blog comments in your in-app notifications so you can catch up later.',
        },
    },
];

const clonePreferences = (key: PreferenceKey): ChannelToggle => ({
    mail: props.channelPreferences[key].mail,
    push: props.channelPreferences[key].push,
    database: props.channelPreferences[key].database,
});

const form = useForm({
    channels: {
        support_ticket: clonePreferences('support_ticket'),
        forum_subscription: clonePreferences('forum_subscription'),
        blog_subscription: clonePreferences('blog_subscription'),
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
            <div class="flex flex-col space-y-10">
                <form @submit.prevent="submit" class="space-y-10">
                    <div
                        v-for="section in sections"
                        :key="section.key"
                        class="space-y-4"
                    >
                        <HeadingSmall :title="section.title" :description="section.description" />

                        <div class="space-y-4">
                            <div class="flex items-start justify-between space-x-4 rounded-lg border p-4">
                                <div class="space-y-1">
                                    <Label class="text-sm font-medium">Email</Label>
                                    <p class="text-sm text-muted-foreground">
                                        {{ section.copy.mail }}
                                    </p>
                                    <p v-if="!props.emailIsVerified" class="text-xs text-muted-foreground">
                                        Verify your email address to enable email notifications.
                                    </p>
                                    <InputError :message="form.errors[`channels.${section.key}.mail`]" />
                                </div>
                                <Switch
                                    v-model:checked="form.channels[section.key].mail"
                                    :disabled="!props.emailIsVerified"
                                    :aria-label="`Toggle ${section.title} email notifications`"
                                />
                            </div>

                            <div class="flex items-start justify-between space-x-4 rounded-lg border p-4">
                                <div class="space-y-1">
                                    <Label class="text-sm font-medium">Push notifications</Label>
                                    <p class="text-sm text-muted-foreground">
                                        {{ section.copy.push }}
                                    </p>
                                    <InputError :message="form.errors[`channels.${section.key}.push`]" />
                                </div>
                                <Switch
                                    v-model:checked="form.channels[section.key].push"
                                    :aria-label="`Toggle ${section.title} push notifications`"
                                />
                            </div>

                            <div class="flex items-start justify-between space-x-4 rounded-lg border p-4">
                                <div class="space-y-1">
                                    <Label class="text-sm font-medium">In-app notifications</Label>
                                    <p class="text-sm text-muted-foreground">
                                        {{ section.copy.database }}
                                    </p>
                                    <InputError :message="form.errors[`channels.${section.key}.database`]" />
                                </div>
                                <Switch
                                    v-model:checked="form.channels[section.key].database"
                                    :aria-label="`Toggle ${section.title} in-app notifications`"
                                />
                            </div>
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
