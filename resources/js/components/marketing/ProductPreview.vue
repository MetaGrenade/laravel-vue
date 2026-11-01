<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { BarChart3, Bell, CreditCard, LayoutGrid, LifeBuoy, MessagesSquare, Package, Settings, Users } from '@lucide/vue';
import { useId } from 'vue';

const page = usePage<SharedData>();
const gradientId = `revenue-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

const nav = [
    { label: 'Dashboard', icon: LayoutGrid, active: true },
    { label: 'Members', icon: Users, active: false },
    { label: 'Billing', icon: CreditCard, active: false },
    { label: 'Products', icon: Package, active: false },
    { label: 'Forum', icon: MessagesSquare, active: false },
    { label: 'Support', icon: LifeBuoy, active: false },
    { label: 'Analytics', icon: BarChart3, active: false },
    { label: 'Settings', icon: Settings, active: false },
];

const stats = [
    { label: 'Total revenue', value: '$12,426', delta: '+12%' },
    { label: 'Active members', value: '2,841', delta: '+18%' },
    { label: 'New signups', value: '324', delta: '+6%' },
    { label: 'Conversion', value: '4.8%', delta: '+0.6%' },
];

const activity = [
    { title: 'New user signed up', detail: 'alex@example.com', time: '2m ago' },
    { title: 'Payment successful', detail: '$49.00 · Pro Plan', time: '12m ago' },
    { title: 'New forum post', detail: 'How to set up queues?', time: '1h ago' },
];
</script>

<template>
    <div class="relative" aria-hidden="true">
        <div
            class="overflow-hidden rounded-2xl border bg-card shadow-[0_30px_80px_-36px_hsl(232_55%_18%/0.45)] dark:shadow-[0_30px_90px_-32px_hsl(230_80%_2%/0.85)]"
        >
            <div class="flex items-center gap-3 border-b px-3 py-2.5 sm:px-4">
                <AppLogoIcon class="size-6" />
                <span class="text-sm font-semibold tracking-tight">{{ page.props.name }}</span>
                <div
                    class="ml-4 hidden h-8 min-w-0 flex-1 items-center gap-2 rounded-full border bg-muted/60 px-3 text-xs text-muted-foreground md:flex"
                >
                    <span class="truncate">Search anything…</span>
                    <kbd class="ml-auto rounded-md border bg-card px-1.5 py-0.5 font-mono text-[0.65rem]">⌘K</kbd>
                </div>
                <Bell class="ml-auto size-4 text-muted-foreground md:ml-3" />
                <span class="hidden items-center gap-2 rounded-full py-1 pr-1 pl-2.5 text-xs font-medium sm:inline-flex">
                    Taylor Kim
                    <span class="flex size-7 items-center justify-center rounded-full bg-primary text-[0.65rem] font-semibold text-primary-foreground"
                        >TK</span
                    >
                </span>
            </div>

            <div class="grid sm:grid-cols-[9.25rem_minmax(0,1fr)]">
                <aside class="hidden border-r p-2.5 sm:block">
                    <ul class="space-y-0.5">
                        <li v-for="item in nav" :key="item.label">
                            <span
                                class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs"
                                :class="item.active ? 'bg-primary font-medium text-primary-foreground' : 'text-muted-foreground'"
                            >
                                <component :is="item.icon" class="size-3.5 shrink-0" />
                                {{ item.label }}
                            </span>
                        </li>
                    </ul>
                </aside>

                <div class="space-y-3 p-3 sm:p-4">
                    <div>
                        <p class="text-sm font-semibold tracking-tight sm:text-base">Welcome back, Taylor</p>
                        <p class="text-[0.7rem] text-muted-foreground sm:text-xs">Here's what's happening with your product today.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-2 xl:grid-cols-4">
                        <div v-for="stat in stats" :key="stat.label" class="rounded-xl border bg-background/60 p-2.5">
                            <p class="truncate text-[0.65rem] text-muted-foreground">{{ stat.label }}</p>
                            <p class="mt-1 text-sm font-semibold tracking-tight tabular-nums">{{ stat.value }}</p>
                            <p class="text-[0.65rem] font-medium text-success">{{ stat.delta }}</p>
                        </div>
                    </div>

                    <div class="grid gap-2 lg:grid-cols-[1.45fr_0.8fr]">
                        <div class="rounded-xl border p-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold">Revenue overview</p>
                                <span class="rounded-full bg-muted px-2 py-0.5 text-[0.65rem] text-muted-foreground">Last 30 days</span>
                            </div>
                            <svg viewBox="0 0 320 112" class="mt-2 h-24 w-full" role="presentation">
                                <defs>
                                    <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="hsl(var(--primary))" stop-opacity="0.35" />
                                        <stop offset="100%" stop-color="hsl(var(--primary))" stop-opacity="0" />
                                    </linearGradient>
                                </defs>
                                <path
                                    :fill="`url(#${gradientId})`"
                                    d="M0 78 C 36 74, 58 62, 86 66 S 132 48, 164 54 S 214 36, 248 32 S 292 24, 320 18 V 112 H 0 Z"
                                />
                                <path
                                    d="M0 78 C 36 74, 58 62, 86 66 S 132 48, 164 54 S 214 36, 248 32 S 292 24, 320 18"
                                    fill="none"
                                    stroke="hsl(var(--primary))"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                />
                                <circle cx="248" cy="32" r="3.5" fill="hsl(var(--card))" stroke="hsl(var(--primary))" stroke-width="2" />
                            </svg>
                            <p class="text-right text-[0.65rem] text-muted-foreground">Apr 21 · $16,240</p>
                        </div>

                        <div class="flex flex-col justify-between rounded-xl border p-3">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-semibold">Plan & billing</p>
                                <span class="rounded-full bg-success/15 px-2 py-0.5 text-[0.65rem] font-semibold text-success">Active</span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold">Pro Plan</p>
                                <p class="text-xs text-muted-foreground">$49 / month</p>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div class="h-full w-2/3 rounded-full bg-primary" />
                                </div>
                                <p class="mt-1.5 text-[0.65rem] text-muted-foreground">Renews May 1, 2025</p>
                            </div>
                        </div>
                    </div>

                    <ul class="divide-y rounded-xl border px-3">
                        <li v-for="item in activity" :key="item.title" class="flex items-center gap-3 py-2">
                            <span class="size-1.5 shrink-0 rounded-full bg-primary" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-medium">{{ item.title }}</span>
                                <span class="block truncate text-[0.65rem] text-muted-foreground">{{ item.detail }}</span>
                            </span>
                            <span class="shrink-0 text-[0.65rem] text-muted-foreground">{{ item.time }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
