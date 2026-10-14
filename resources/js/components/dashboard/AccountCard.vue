<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { DashboardAccount } from '@/types/dashboard';
import { Link } from '@inertiajs/vue3';
import { ShieldAlert, ShieldCheck, UserRound } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    account: DashboardAccount;
    sections: { forum: boolean; support: boolean; commerce: boolean };
}>();

const since = computed(() =>
    props.account.member_since ? new Date(props.account.member_since).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null,
);

const links = computed(() =>
    [
        { label: 'Shop', href: route('shop.index'), show: props.sections.commerce },
        { label: 'My orders', href: route('shop.orders'), show: props.sections.commerce },
        { label: 'Wishlist', href: route('shop.wishlist'), show: props.sections.commerce },
        { label: 'Saved addresses', href: route('settings.addresses.index'), show: props.sections.commerce },
        { label: 'Billing', href: route('settings.billing.index'), show: true },
        { label: 'Get support', href: route('support'), show: props.sections.support },
        { label: 'Notifications', href: route('settings.notifications.edit'), show: true },
        { label: 'Profile', href: route('profile.edit'), show: true },
    ].filter((link) => link.show),
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2"><UserRound class="size-5 text-muted-foreground" aria-hidden="true" /> Your account</CardTitle>
            <CardDescription>Keep it secure, and jump to where you manage things.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-sidebar-border/60 p-3 dark:border-sidebar-border">
                <div class="flex items-center gap-2">
                    <ShieldCheck v-if="account.two_factor_enabled" class="size-5 text-success" aria-hidden="true" />
                    <ShieldAlert v-else class="size-5 text-muted-foreground" aria-hidden="true" />
                    <div>
                        <p class="font-medium">Two-factor authentication</p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                account.two_factor_enabled
                                    ? 'Your account asks for a code when you sign in.'
                                    : 'Add a second step so a stolen password is not enough.'
                            }}
                        </p>
                    </div>
                </div>
                <Button size="sm" :variant="account.two_factor_enabled ? 'outline' : 'default'" as-child>
                    <Link :href="route('security.edit')">{{ account.two_factor_enabled ? 'Manage' : 'Turn on' }}</Link>
                </Button>
            </div>

            <dl class="grid grid-cols-2 gap-3">
                <div v-if="sections.forum">
                    <dt class="text-xs text-muted-foreground">Reputation</dt>
                    <dd class="font-medium tabular-nums">{{ account.reputation }} <span class="font-normal text-muted-foreground">points</span></dd>
                </div>
                <div v-if="sections.forum">
                    <dt class="text-xs text-muted-foreground">Badges</dt>
                    <dd class="font-medium tabular-nums">{{ account.badges }}</dd>
                </div>
                <div v-if="since">
                    <dt class="text-xs text-muted-foreground">Member since</dt>
                    <dd class="font-medium">{{ since }}</dd>
                </div>
            </dl>

            <div class="flex flex-wrap gap-2">
                <Button v-for="link in links" :key="link.label" size="sm" variant="outline" as-child>
                    <Link :href="link.href">{{ link.label }}</Link>
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
