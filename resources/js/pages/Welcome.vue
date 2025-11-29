<script setup lang="ts">
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Anvil } from 'lucide-vue-next';
import { Card, CardContent } from '@/components/ui/card'
import Autoplay from 'embla-carousel-autoplay'
import { Carousel, CarouselContent, CarouselItem, CarouselNext, CarouselPrevious } from '@/components/ui/carousel'
import AppLayout from '@/layouts/AppLayout.vue';

const page = usePage<SharedData>();
const techStack = [
    {
        name: 'Laravel',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <path fill="#F55247" d="M40.8 24.5 32 29.6v12.7l8.8-5.1V24.5Z"/>
                <path fill="#F55247" d="m32 29.6-8.8-5.1v12.7l8.8 5.1V29.6Z" opacity=".85"/>
                <path fill="#F76555" d="M32 16 23.2 21.2 32 26.3l8.8-5.1L32 16Z"/>
                <path fill="#F55247" d="m23.2 21.2-8.7 5.1L23.2 31l8.8-4.7-8.8-5.1Z" opacity=".9"/>
                <path fill="#F8877A" d="m40.8 21.2 8.7 5.1-8.7 4.7-8.8-4.7 8.8-5.1Z"/>
            </svg>`,
    },
    {
        name: 'Vue 3',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <path fill="#41B883" d="M38.4 8 32 18.4 25.6 8H2.7L32 56 61.3 8H38.4Z"/>
                <path fill="#35495E" d="M38.4 8 32 18.4 25.6 8h-9.2L32 39.7 47.6 8h-9.2Z"/>
            </svg>`,
    },
    {
        name: 'Inertia',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <circle cx="20" cy="22" r="12" fill="#5C7CFA"/>
                <circle cx="44" cy="42" r="12" fill="#82C7FF"/>
                <path fill="#1E293B" d="M24 40c1.8 2.6 6.5 5.9 12.8 3.8 6-2 8-8.2 7.8-11.4-3.4 3.7-10.5 6.3-20.6 7.6Z" opacity=".55"/>
            </svg>`,
    },
    {
        name: 'Vite',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <path fill="#654FF0" d="M8 10.8 32 56l24-45.2-24 8.6-24-8.6Z"/>
                <path fill="#FFD166" d="M32 12v20l10.5-16.2L32 12Z"/>
                <path fill="#FF8B3D" d="M32 12 21.5 15.8 32 32V12Z"/>
            </svg>`,
    },
    {
        name: 'Tailwind CSS',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <path fill="#38BDF8" d="M32 16c-8 0-13.3 4-16 12 3.2-4 6.8-5.4 10.8-4.2 2.4.8 4.1 2.8 6 5.1 2.9 3.5 6.2 7.5 13.2 7.5 8 0 13.3-4 16-12-3.2 4-6.8 5.4-10.8 4.2-2.4-.8-4.1-2.8-6-5.1C42.3 20 39 16 32 16Z"/>
                <path fill="#0EA5E9" d="M16.1 33.6C8 33.6 2.7 37.6 0 45.6c3.2-4 6.8-5.4 10.8-4.2 2.4.8 4.1 2.8 6 5.1 2.9 3.5 6.2 7.5 13.2 7.5 8 0 13.3-4 16-12-3.2 4-6.8 5.4-10.8 4.2-2.4-.8-4.1-2.8-6-5.1-2.9-3.5-6.2-7.5-13.2-7.5Z" opacity=".9"/>
            </svg>`,
    },
    {
        name: 'TypeScript',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <rect width="64" height="64" rx="8" fill="#3178C6"/>
                <path fill="#fff" d="M17 32.5h10.4v-4H12.5v4h3.8v15h4.7v-15Zm11.8 12.7c1.3 2.8 4.1 4.6 8.3 4.6 5 0 7.9-2.6 7.9-6.1 0-2.9-1.7-4.7-5.9-5.8l-2.4-.7c-2-.6-2.8-1.2-2.8-2.2 0-1.2 1.1-2 3-2 2 0 3.3.8 4 2.4l3.5-2c-1.3-2.6-3.9-4.3-7.4-4.3-4.3 0-7.4 2.4-7.4 6 0 3 1.9 4.8 5.8 5.9l2.5.7c2 .6 2.8 1.2 2.8 2.3 0 1.1-1 2-3.2 2-2.5 0-4-.9-4.8-2.6l-3.9 2.2Z"/>
            </svg>`,
    },
    {
        name: 'Shadcn UI',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <rect x="8" y="8" width="22" height="22" rx="4" fill="#0F172A"/>
                <rect x="34" y="34" width="22" height="22" rx="4" fill="#0F172A"/>
                <path fill="#E5E7EB" d="M30 16h4v32h-4z"/>
                <path fill="#E5E7EB" d="M16 30v4h32v-4z"/>
            </svg>`,
    },
    {
        name: 'Vue Sonner',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <rect x="10" y="14" width="44" height="36" rx="10" fill="#111827" opacity=".92"/>
                <path fill="#FACC15" d="M20 26h24v4H20z"/>
                <path fill="#F97316" d="M20 34h18v4H20z" opacity=".9"/>
                <circle cx="22" cy="22" r="8" fill="#34D399" opacity=".9"/>
            </svg>`,
    },
    {
        name: 'Pusher',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <path fill="#4C51BF" d="M12 14h40v8H12z"/>
                <path fill="#7180D8" d="M18 28h28v8H18z"/>
                <path fill="#A5B4FC" d="M24 42h16v8H24z"/>
                <circle cx="32" cy="32" r="30" stroke="#4C51BF" stroke-width="4" opacity=".6"/>
            </svg>`,
    },
    {
        name: 'Ziggy',
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="h-12 w-12" fill="none">
                <rect x="10" y="10" width="44" height="44" rx="8" fill="#111827"/>
                <path fill="#67E8F9" d="M24 20h24l-18 24h18v4H24l18-24H24v-4Z"/>
            </svg>`,
    },
];
const websiteSections = computed(() => {
    const defaults = { blog: true, forum: true, support: true } as const;
    const settings = page.props.settings?.website_sections ?? defaults;

    return {
        blog: settings.blog ?? defaults.blog,
        forum: settings.forum ?? defaults.forum,
        support: settings.support ?? defaults.support,
    } as const;
});
</script>

<template>
    <AppLayout>
        <Head title="Laravel Vue Starter Kit — Production-ready Laravel + Vue Boilerplate for SaaS" />

        <div class="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a]">
            <main class="flex flex-1 justify-center p-6">
                <div class="flex w-full max-w-7xl flex-col gap-12">
                    <section class="overflow-hidden rounded-xl bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] lg:flex lg:items-center lg:gap-12 lg:p-12">
                        <div class="flex-1 space-y-6">
                            <div class="inline-flex items-center rounded-full bg-[#f9f3e6] px-3 py-1 text-xs font-medium text-[#8b5a00] dark:bg-[#261f14] dark:text-[#f3d29e]">
                                Laravel + Vue SaaS Starter kit
                            </div>
                            <div class="space-y-4">
                                <h1 class="text-3xl font-semibold leading-tight tracking-tight text-[#1b1b18] dark:text-[#EDEDEC] sm:text-4xl">
                                    Launch your SaaS app with ready-to-ship modules
                                </h1>
                                <p class="max-w-2xl text-base text-[#706f6c] dark:text-[#A1A09A]">
                                    A cohesive boilerplate that ships with content, conversations, support, billing, and an admin control panel—all styled with the existing design system so you can customize and deploy faster.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <Link
                                    :href="route('register')"
                                    class="inline-flex items-center justify-center rounded-sm bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white shadow-[0px_1px_2px_rgba(0,0,0,0.12)] transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    Create Demo Account
                                </Link>
                                <Link
                                    :href="route('pricing')"
                                    class="inline-flex items-center justify-center rounded-sm border border-[#19140035] px-5 py-2 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    Pricing
                                </Link>
                            </div>
                            <div class="flex flex-wrap gap-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]"></span>
                                    Integrated auth, notifications, and billing
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]"></span>
                                    Built with Laravel + Vue 3 + Tailwind
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]"></span>
                                    Admin Control Panel with role-based access
                                </div>
                            </div>
                        </div>
                        <div class="mt-10 flex flex-1 justify-center lg:mt-0">
                            <div class="w-full max-w-md rounded-lg bg-gradient-to-br from-[#fff7e6] via-[#f4f0e8] to-[#e8e5dc] p-6 text-[#1b1b18] shadow-[0px_10px_40px_rgba(0,0,0,0.08)] dark:from-[#1d1c19] dark:via-[#171612] dark:to-[#11100d] dark:text-[#EDEDEC]">
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Starter Layout</p>
                                        <h2 class="text-xl font-semibold">Curated Modules</h2>
                                        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Prebuilt flows that keep brand consistency across every surface.</p>
                                    </div>
                                    <ul class="space-y-3 text-sm">
                                        <li class="flex items-start gap-3">
                                            <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">1</span>
                                            <div>
                                                <p class="font-medium">Story-driven Blog</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Feature posts, categories, tags, and RSS without extra setup.</p>
                                            </div>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">2</span>
                                            <div>
                                                <p class="font-medium">Community Forum</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Boards, threads, moderation, and subscriptions ready to go.</p>
                                            </div>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">3</span>
                                            <div>
                                                <p class="font-medium">Support & Billing</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Ticketing, FAQs, and subscription management live together.</p>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="flex flex-col gap-6">
                            <Carousel
                                class="w-full w-max-7xl"
                                :opts="{
                                  align: 'start',
                                  loop: true,
                                }"
                                :plugins="[Autoplay({
                                  delay: 2000,
                                })]"
                            >
                                <CarouselContent class="-ml-1">
                                    <CarouselItem
                                        v-for="tech in techStack"
                                        :key="tech.name"
                                        class="pl-1 md:basis-1/4 lg:basis-1/5"
                                    >
                                        <div class="p-1">
                                            <Card>
                                                <CardContent class="flex aspect-square flex-col items-center justify-center gap-3 p-4">
                                                    <div
                                                        class="flex h-16 w-16 items-center justify-center rounded-lg bg-[#f3f0ea] text-[#1b1b18] dark:bg-[#171612] dark:text-[#EDEDEC]"
                                                        v-html="tech.svg"
                                                        aria-hidden="true"
                                                    />
                                                    <p class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                                                        {{ tech.name }}
                                                    </p>
                                                </CardContent>
                                            </Card>
                                        </div>
                                    </CarouselItem>
                                </CarouselContent>
                                <CarouselPrevious class="text-white" />
                                <CarouselNext class="text-white" />
                            </Carousel>
                        </div>
                    </section>

                    <section class="grid gap-4 lg:grid-cols-3">
                        <div
                            v-if="websiteSections.blog"
                            class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                        >
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Editorial</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Blog</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Publish announcements, guides, and release notes with SEO-friendly layouts, tags, and comments.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('blogs.index')"
                                    class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    View Articles
                                </Link>
                            </div>
                        </div>

                        <div
                            v-if="websiteSections.forum"
                            class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                        >
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Community</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Forum</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Organized boards, thread subscriptions, and moderation tools to keep discussions healthy.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('forum.index')"
                                    class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    Browse Threads
                                </Link>
                            </div>
                        </div>

                        <div
                            v-if="websiteSections.support"
                            class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                        >
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Help</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Support Center</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Ticketing, FAQs, and satisfaction surveys that plug directly into your member accounts.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('support')"
                                    class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    Open Support
                                </Link>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)] lg:col-span-2">
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Monetization</p>
                            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-baseline sm:justify-between">
                                <div>
                                    <h3 class="text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Pricing & Billing</h3>
                                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        Stripe-backed subscriptions, invoices, and webhooks with member-facing billing screens.
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Link
                                        :href="route('settings.billing.index')"
                                        class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    >
                                        Manage Subscriptions
                                    </Link>
                                </div>
                            </div>
                            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                                <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="font-semibold">Subscription Flows</p>
                                    <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Upgrade, cancel, resume, and retry payments directly from member settings.</p>
                                </div>
                                <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="font-semibold">Invoices & Webhooks</p>
                                    <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Audit webhook deliveries and keep invoices aligned with your Stripe catalog.</p>
                                </div>
                                <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="font-semibold">Pricing Presets</p>
                                    <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Start with common plan tiers and tailor the copy before launch.</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Insights</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Member Dashboard</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Unified dashboard that surfaces blog recommendations, forum engagement, and support updates.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('dashboard')"
                                    class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    Visit Dashboard
                                </Link>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Operations</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Admin Control Panel</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Manage users, permissions, support queues, and moderation workflows inside the Inertia-powered ACP.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('acp.dashboard')"
                                    class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    Visit Admin Dashboard
                                </Link>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Toggles</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Website Modules</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Enable or disable the blog, forum, support center, billing, and social logins with safe defaults and 404 enforcement.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('acp.system')"
                                    class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    View System Settings
                                </Link>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Security</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Identity & MFA</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                TOTP multi-factor authentication, recovery codes, session management, and OAuth identity linking live in member settings.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('security.edit')"
                                    class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    Review Security
                                </Link>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                            <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">API</p>
                            <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Docs & Tokens</h3>
                            <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                Versioned `/api/v1` endpoints, Swagger UI at <code>/api/docs</code>, and Sanctum token management for native clients.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Link
                                    :href="route('api.docs')"
                                    class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    View API Docs
                                </Link>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl bg-[#11110f] px-6 py-10 text-white bg-gradient-to-br from-[#fff7e6] via-[#f4f0e8] to-[#e8e5dc] shadow-[inset_0px_0px_0px_1px_rgba(255,255,255,0.06)] dark:from-[#1d1c19] dark:via-[#171612] dark:to-[#11100d] dark:text-[#EDEDEC]">
                        <div class="flex flex-col items-start gap-6 text-left sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-3">
                                <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Launch Faster</p>
                                <h3 class="text-2xl font-semibold leading-tight text-[#1b1b18] dark:text-[#EDEDEC]">Plug into <Anvil class="inline text-[#8b5a00] dark:text-[#f3d29e]" /> <span class="text-[#8b5a00] dark:text-[#f3d29e]">MetaForge</span> and ship your product story</h3>
                                <p class="max-w-2xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Every module uses the same typography, spacing, and components so you can focus on content, customization, and onboarding instead of wiring basics together.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <Link
                                    :href="route('register')"
                                    class="inline-flex items-center rounded-sm px-5 py-2 text-sm font-medium text-[#1b1b18] hover:text-white dark:text-[#0f0f0d] transition bg-[#f3d29e] hover:bg-black dark:bg-[#f3d29e] dark:hover:bg-white"
                                >
                                    Get Started Free
                                </Link>
                                <Link
                                    v-if="websiteSections.blog"
                                    :href="route('blogs.index')"
                                    class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    See it in action
                                </Link>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                        <div class="flex flex-col gap-6">
                            <div class="space-y-2">
                                <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Tech Stack</p>
                                <h3 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Frontend, Backend, and Requirements</h3>
                                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">A concise overview of the frameworks, tooling, and references that power this starter kit.</p>
                            </div>
                            <div class="grid gap-6 md:grid-cols-3">
                                <div class="rounded-lg bg-[#f9f7f2] p-5 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Frontend</p>
                                    <h4 class="mt-2 text-lg font-semibold">Vue 3 + Inertia</h4>
                                    <ul class="mt-3 space-y-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        <li>Vue 3 with TypeScript and Inertia.js for SPA routing.</li>
                                        <li>Tailwind CSS + shadcn-inspired components for UI.</li>
                                        <li>Vite 6 for dev server and bundling.</li>
                                        <li>SSR entry point in <code>resources/js/ssr.ts</code>.</li>
                                    </ul>
                                </div>
                                <div class="rounded-lg bg-[#f9f7f2] p-5 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Backend</p>
                                    <h4 class="mt-2 text-lg font-semibold">Laravel Core</h4>
                                    <ul class="mt-3 space-y-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        <li>Laravel 12 with Sanctum tokens and Spatie Permissions.</li>
                                        <li>Stripe billing via Cashier plus webhook visibility.</li>
                                        <li>Queues, events, and broadcasting scaffolding built in.</li>
                                        <li>Inertia controllers deliver shared props to the SPA.</li>
                                    </ul>
                                </div>
                                <div class="rounded-lg bg-[#f9f7f2] p-5 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Requirements</p>
                                    <h4 class="mt-2 text-lg font-semibold">Environment</h4>
                                    <ul class="mt-3 space-y-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        <li>PHP 8.2+ with Composer.</li>
                                        <li>Node.js 20+ with npm or pnpm.</li>
                                        <li>MySQL/MariaDB or PostgreSQL configured in <code>.env</code>.</li>
                                        <li>Optional Pusher credentials for realtime broadcasting.</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="grid gap-6 md:grid-cols-2">
                                <div class="rounded-lg bg-[#f9f7f2] p-5 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Continuous Integration</p>
                                    <h4 class="mt-2 text-lg font-semibold">GitHub Actions</h4>
                                    <ul class="mt-3 space-y-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        <li>Automated linting with PHP Pint plus ESLint/Prettier via <code>linter</code> workflow.</li>
                                        <li>Full build, Ziggy config generation, and asset compilation on pushes and PRs.</li>
                                        <li>Reusable pipeline targeting <code>develop</code> and <code>main</code> to keep both branches healthy.</li>
                                    </ul>
                                </div>
                                <div class="rounded-lg bg-[#f9f7f2] p-5 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Testing</p>
                                    <h4 class="mt-2 text-lg font-semibold">Unit & Feature Coverage</h4>
                                    <ul class="mt-3 space-y-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                        <li>PHPUnit runs automatically in the <code>tests</code> workflow with Xdebug coverage enabled.</li>
                                        <li>Example suites live in <code>tests/Feature</code> and <code>tests/Unit</code> to guide new specs.</li>
                                        <li>Quickstart locally with <code>php artisan test</code> after installing Composer dependencies.</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <a
                                    href="https://github.com/MetaGrenade/laravel-vue"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    MetaForge GitHub Repository
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://laravel.com/docs/12.x"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Laravel 12.x Documentation
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://vuejs.org/guide/introduction.html"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Vue 3 Guide
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://inertiajs.com/docs/v2/getting-started/index"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Inertia 2.x Documentation
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://tailwindcss.com/docs"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Tailwind CSS Documentation
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://lucide.dev/icons/"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Lucide Icons
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://vue-sonner.vercel.app/"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Vue Sonner Toast Component
                                    <span aria-hidden="true">↗</span>
                                </a>
                                <a
                                    href="https://tiptap.dev/docs/editor/getting-started/install/vue3"
                                    class="inline-flex items-center justify-between rounded-lg border border-[#19140035] bg-white px-4 py-3 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Tiptap Editor
                                    <span aria-hidden="true">↗</span>
                                </a>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </AppLayout>
</template>
