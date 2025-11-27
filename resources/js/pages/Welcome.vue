<script setup lang="ts">
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a]">
        <header class="mb-8 flex w-full justify-center px-6 pt-6">
            <nav class="flex w-full max-w-7xl items-center justify-end gap-4 text-sm">
                <Link
                    :href="route('pricing')"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Pricing
                </Link>
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('register')"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                    >
                        Get started
                    </Link>
                </template>
            </nav>
        </header>

        <main class="flex flex-1 justify-center px-6 pb-16">
            <div class="flex w-full max-w-7xl flex-col gap-12">
                <section class="overflow-hidden rounded-xl bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] lg:flex lg:items-center lg:gap-12 lg:p-12">
                    <div class="flex-1 space-y-6">
                        <div class="inline-flex items-center rounded-full bg-[#f9f3e6] px-3 py-1 text-xs font-medium text-[#8b5a00] dark:bg-[#261f14] dark:text-[#f3d29e]">
                            Starter kit
                        </div>
                        <div class="space-y-4">
                            <h1 class="text-3xl font-semibold leading-tight tracking-tight text-[#1b1b18] dark:text-[#EDEDEC] sm:text-4xl">
                                Launch your community app with ready-to-ship modules
                            </h1>
                            <p class="max-w-2xl text-base text-[#706f6c] dark:text-[#A1A09A]">
                                A cohesive starter that ships with content, conversations, support, billing, and a member dashboard—all styled with the existing design system so you can customize and deploy faster.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <Link
                                :href="route('register')"
                                class="inline-flex items-center justify-center rounded-sm bg-[#1b1b18] px-5 py-2 text-sm font-medium text-white shadow-[0px_1px_2px_rgba(0,0,0,0.12)] transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                            >
                                Create an account
                            </Link>
                            <Link
                                :href="route('pricing')"
                                class="inline-flex items-center justify-center rounded-sm border border-[#19140035] px-5 py-2 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                            >
                                View pricing
                            </Link>
                            <Link
                                v-if="websiteSections.blog"
                                :href="route('blogs.index')"
                                class="inline-flex items-center justify-center rounded-sm border border-[#19140035] px-5 py-2 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                            >
                                Explore the blog
                            </Link>
                            <Link
                                v-if="websiteSections.support"
                                :href="route('support')"
                                class="inline-flex items-center justify-center rounded-sm border border-[#19140035] px-5 py-2 text-sm font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                            >
                                Visit support center
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
                        </div>
                    </div>
                    <div class="mt-10 flex flex-1 justify-center lg:mt-0">
                        <div class="w-full max-w-md rounded-lg bg-gradient-to-br from-[#fff7e6] via-[#f4f0e8] to-[#e8e5dc] p-6 text-[#1b1b18] shadow-[0px_10px_40px_rgba(0,0,0,0.08)] dark:from-[#1d1c19] dark:via-[#171612] dark:to-[#11100d] dark:text-[#EDEDEC]">
                            <div class="space-y-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Starter layout</p>
                                    <h2 class="text-xl font-semibold">Curated modules</h2>
                                    <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Prebuilt flows that keep brand consistency across every surface.</p>
                                </div>
                                <ul class="space-y-3 text-sm">
                                    <li class="flex items-start gap-3">
                                        <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">1</span>
                                        <div>
                                            <p class="font-medium">Story-driven blog</p>
                                            <p class="text-[#706f6c] dark:text-[#A1A09A]">Feature posts, categories, tags, and RSS without extra setup.</p>
                                        </div>
                                    </li>
                                    <li class="flex items-start gap-3">
                                        <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">2</span>
                                        <div>
                                            <p class="font-medium">Community forum</p>
                                            <p class="text-[#706f6c] dark:text-[#A1A09A]">Boards, threads, moderation, and subscriptions ready to go.</p>
                                        </div>
                                    </li>
                                    <li class="flex items-start gap-3">
                                        <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">3</span>
                                        <div>
                                            <p class="font-medium">Support & billing</p>
                                            <p class="text-[#706f6c] dark:text-[#A1A09A]">Ticketing, FAQs, and subscription management live together.</p>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
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
                                View posts
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
                                Browse threads
                            </Link>
                        </div>
                    </div>

                    <div
                        v-if="websiteSections.support"
                        class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                    >
                        <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Help</p>
                        <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Support center</h3>
                        <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            Ticketing, FAQs, and satisfaction surveys that plug directly into your member accounts.
                        </p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <Link
                                :href="route('support')"
                                class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                            >
                                Open support
                            </Link>
                        </div>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)] lg:col-span-2">
                        <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Monetization</p>
                        <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-baseline sm:justify-between">
                            <div>
                                <h3 class="text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Pricing & billing</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Stripe-backed subscriptions, invoices, and webhooks with member-facing billing screens.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    :href="route('register')"
                                    class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                >
                                    View plans & sign up
                                </Link>
                                <Link
                                    :href="route('settings.billing.index')"
                                    class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                >
                                    Manage billing
                                </Link>
                            </div>
                        </div>
                        <div class="mt-6 grid gap-4 sm:grid-cols-3">
                            <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                <p class="font-semibold">Subscription flows</p>
                                <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Upgrade, cancel, resume, and retry payments directly from member settings.</p>
                            </div>
                            <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                <p class="font-semibold">Invoices & webhooks</p>
                                <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Audit webhook deliveries and keep invoices aligned with your Stripe catalog.</p>
                            </div>
                            <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                <p class="font-semibold">Pricing presets</p>
                                <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Start with common plan tiers and tailor the copy before launch.</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                        <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Insights</p>
                        <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Member dashboard</h3>
                        <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            Unified dashboard that surfaces blog recommendations, forum engagement, and support updates.
                        </p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <Link
                                :href="route('dashboard')"
                                class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                            >
                                Go to dashboard
                            </Link>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl bg-[#11110f] px-6 py-10 text-white shadow-[inset_0px_0px_0px_1px_rgba(255,255,255,0.06)] dark:bg-[#0f0f0d]">
                    <div class="flex flex-col items-start gap-6 text-left sm:flex-row sm:items-center sm:justify-between">
                        <div class="space-y-3">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#f3d29e]">Launch faster</p>
                            <h3 class="text-2xl font-semibold leading-tight">Plug into the starter and ship your product story</h3>
                            <p class="max-w-2xl text-sm text-[#d7d5cf]">
                                Every module uses the same typography, spacing, and components so you can focus on content, customization, and onboarding instead of wiring basics together.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <Link
                                :href="route('register')"
                                class="inline-flex items-center rounded-sm bg-white px-5 py-2 text-sm font-medium text-[#0f0f0d] transition hover:bg-[#f3d29e]"
                            >
                                Start free
                            </Link>
                            <Link
                                v-if="websiteSections.blog"
                                :href="route('blogs.index')"
                                class="inline-flex items-center rounded-sm border border-[#ffffff33] px-5 py-2 text-sm font-medium text-white transition hover:border-white"
                            >
                                See it in action
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>
