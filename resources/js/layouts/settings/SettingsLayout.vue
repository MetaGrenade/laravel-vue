<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, CreditCard, KeyRound, Lock, MapPin, Palette, Receipt, ShieldCheck, User, Wallet } from '@lucide/vue';
import { computed, type Component } from 'vue';

interface SettingsNavItem {
    title: string;
    href: string;
    icon: Component;
    /** Only shown while this section of the site is switched on. */
    section?: 'commerce';
}

const allNavGroups: { title: string; items: SettingsNavItem[] }[] = [
    {
        title: 'Account',
        items: [
            { title: 'Profile', href: '/settings/profile', icon: User },
            { title: 'Password', href: '/settings/password', icon: KeyRound },
            { title: 'Security', href: '/settings/security', icon: ShieldCheck },
            { title: 'Notifications', href: '/settings/notifications', icon: Bell },
            { title: 'Privacy', href: '/settings/privacy', icon: Lock },
            { title: 'Appearance', href: '/settings/appearance', icon: Palette },
        ],
    },
    {
        title: 'Billing',
        items: [
            { title: 'Subscription', href: '/settings/billing', icon: CreditCard },
            { title: 'Payment methods', href: '/settings/billing/payment-methods', icon: Wallet },
            { title: 'Invoices', href: '/settings/billing/invoices', icon: Receipt },
            { title: 'Addresses', href: '/settings/addresses', icon: MapPin, section: 'commerce' },
        ],
    },
];

const page = usePage<SharedData>();

const sections = computed(() => page.props.settings?.website_sections);

const navGroups = computed(() =>
    allNavGroups
        .map((group) => ({ ...group, items: group.items.filter((item) => !item.section || sections.value?.[item.section] !== false) }))
        .filter((group) => group.items.length > 0),
);

const currentPath = computed(() => page.url.split(/[?#]/)[0]);

const isActive = (href: string) => currentPath.value === href;

const linkClass = (href: string) =>
    cn(
        'flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm whitespace-nowrap text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
        isActive(href) && 'bg-accent font-medium text-foreground',
    );
</script>

<template>
    <div class="px-4 py-6">
        <div class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight">Settings</h1>
            <p class="text-sm text-muted-foreground">Manage your profile, security and billing.</p>
        </div>

        <!-- Mobile / tablet: horizontally scrolling tabs -->
        <nav class="-mx-4 mb-6 scrollbar-thin overflow-x-auto border-b px-4 lg:hidden" aria-label="Settings sections">
            <ul class="flex w-max gap-1 pb-2">
                <template v-for="group in navGroups" :key="group.title">
                    <li v-for="item in group.items" :key="item.href">
                        <Link :href="item.href" :class="linkClass(item.href)" :aria-current="isActive(item.href) ? 'page' : undefined">
                            <component :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </Link>
                    </li>
                </template>
            </ul>
        </nav>

        <div class="grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <aside class="hidden lg:block">
                <nav class="sticky top-20 space-y-5" aria-label="Settings sections">
                    <div v-for="group in navGroups" :key="group.title">
                        <p class="mb-1.5 px-2.5 text-xs font-medium text-muted-foreground/80">{{ group.title }}</p>
                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.href">
                                <Link :href="item.href" :class="linkClass(item.href)" :aria-current="isActive(item.href) ? 'page' : undefined">
                                    <component :is="item.icon" :class="cn('size-4', isActive(item.href) && 'text-primary')" />
                                    {{ item.title }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>
            </aside>

            <div class="min-w-0">
                <!-- Settings forms read best at a comfortable measure. Several pages use their own cards, so no wrapper card here. -->
                <section class="max-w-3xl space-y-10">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
