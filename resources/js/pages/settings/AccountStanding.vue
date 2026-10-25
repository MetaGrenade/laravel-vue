<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';

/**
 * The warnings a member has been given and any ban they have been under (docs/moderation.md). What a moderator wrote for staff only, and
 * who they were, never reach this page.
 */
interface Notice {
    id: number;
    type: 'warning' | 'ban' | 'unban';
    reason: string | null;
    expires_at: string | null;
    subject: { label: string; url: string | null } | null;
    created_at: string | null;
}

defineProps<{ notices: Notice[] }>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Account standing', href: '/settings/account-standing' }];

const day = (value: string | null) => (value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '');

const title = (notice: Notice) => {
    switch (notice.type) {
        case 'warning':
            return 'Warning';
        case 'ban':
            return notice.expires_at ? `Suspended until ${day(notice.expires_at)}` : 'Banned';
        default:
            return 'Ban lifted';
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Account standing" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Account standing"
                    description="The warnings you have been given, and any time your account has been suspended."
                />

                <Card v-if="notices.length === 0">
                    <CardContent class="flex items-center gap-3 pt-6 text-sm">
                        <ShieldCheck class="size-5 shrink-0 text-success" aria-hidden="true" />
                        <p>Your account is in good standing. You have had no warnings.</p>
                    </CardContent>
                </Card>

                <ul v-else class="space-y-3">
                    <li v-for="notice in notices" :key="notice.id">
                        <Card>
                            <CardContent class="space-y-2 pt-6 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <Badge :variant="notice.type === 'unban' ? 'outline' : notice.type === 'ban' ? 'destructive' : 'highlight'">
                                            {{ title(notice) }}
                                        </Badge>
                                    </div>
                                    <time class="text-xs text-muted-foreground" :datetime="notice.created_at ?? undefined">{{
                                        day(notice.created_at)
                                    }}</time>
                                </div>
                                <p v-if="notice.reason" class="whitespace-pre-line">{{ notice.reason }}</p>
                                <p v-if="notice.subject" class="text-xs text-muted-foreground">
                                    About:
                                    <a v-if="notice.subject.url" :href="notice.subject.url" class="underline">{{ notice.subject.label }}</a>
                                    <template v-else>{{ notice.subject.label }}</template>
                                </p>
                            </CardContent>
                        </Card>
                    </li>
                </ul>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
