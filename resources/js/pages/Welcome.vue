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
                                    <CarouselItem v-for="(_, index) in 10" :key="index" class="pl-1 md:basis-1/4 lg:basis-1/5">
                                        <div class="p-1">
                                            <Card>
                                                <CardContent class="flex aspect-square items-center justify-center p-4">
                                                    <span class="text-2xl font-semibold">{{ index + 1 }}</span>
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
