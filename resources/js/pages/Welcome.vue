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

/**
 * Load raw SVG strings (inline) from resources/images/tech-icons
 * We use 'raw' so we can insert SVG markup into the page and recolor it with currentColor.
 */
const rawIconModules = import.meta.glob('../../images/tech-icons/*.svg', { as: 'raw', eager: true }) as Record<string, string>;

function sanitizeAndPrepareSvg(rawSvg: string) {
    if (!rawSvg) return '';

    // remove XML prologue and doctype
    let svg = rawSvg.replace(/<\?xml[\s\S]*?\?>/gi, '').replace(/<!DOCTYPE[\s\S]*?>/gi, '');

    // Remove any HTML comments
    svg = svg.replace(/<!--[\s\S]*?-->/g, '');

    // Replace the opening <svg ...> tag:
    // - remove width/height attributes
    // - remove existing style attribute (we'll add our own sizing style)
    // - inject a small inline style to force consistent height
    // - set role/focusable attributes for accessibility
    svg = svg.replace(/<svg([^>]*)>/i, (match, attrs) => {
        // strip width/height/style attributes from attrs
        const cleaned = attrs
            .replace(/\s(width|height)=["'][^"']*["']/gi, '')
            .replace(/\s(style)=["'][^"']*["']/gi, '');

        // ensure there's a space between <svg and attributes if attrs not empty
        const attrsFragment = (cleaned && cleaned.trim().length) ? ' ' + cleaned.trim() : '';

        // add inline style for consistent height (3rem -> 48px equals Tailwind h-12)
        // display:block prevents inline-gap issues in some browsers
        const inlineStyle = 'style="height:3rem;width:auto;display:block"';

        return `<svg${attrsFragment} ${inlineStyle} role="img" focusable="false" aria-hidden="false">`;
    });

    // (optional) remove unnecessary xmlns:xlink attributes to reduce clutter
    svg = svg.replace(/\s+xmlns:[a-zA-Z]+=["'][^"']*["']/g, '');

    return svg.trim();
}

const techIconsInline = Object.keys(rawIconModules)
    .map((fullPath) => {
        const parts = fullPath.split('/');
        const filename = parts[parts.length - 1];
        const name = filename.replace('.svg', '');
        const raw = rawIconModules[fullPath] ?? '';
        return {
            name,
            svg: sanitizeAndPrepareSvg(raw),
        };
    })
    .sort((a, b) => a.name.localeCompare(b.name));
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
                                    Show founders, clients, and contributors a SaaS that’s already selling
                                </h1>
                                <p class="max-w-2xl text-base text-[#706f6c] dark:text-[#A1A09A]">
                                    This Laravel + Vue starter arrives with every marketing surface live—blog, forum, support, billing, and admin—so agencies, indie founders, and OSS adopters can point to proof before writing custom code.
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
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Demo launches and updates instantly with tags, categories, and RSS baked in.</p>
                                            </div>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">2</span>
                                            <div>
                                                <p class="font-medium">Community Forum</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Invite early adopters and clients into moderated threads with subscriptions ready.</p>
                                            </div>
                                        </li>
                                        <li class="flex items-start gap-3">
                                            <span class="mt-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-white text-xs font-semibold text-[#1b1b18] shadow-[0px_1px_2px_rgba(0,0,0,0.08)] dark:bg-[#0f0f0d] dark:text-[#EDEDEC]">3</span>
                                            <div>
                                                <p class="font-medium">Support & Billing</p>
                                                <p class="text-[#706f6c] dark:text-[#A1A09A]">Prove readiness with ticketing, FAQs, and subscription management in one flow.</p>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="flex flex-col gap-6">
                            <div class="flex flex-col gap-2">
                                <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Marketing & SEO Ready</p>
                                <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Built to earn trust from the first scroll</h2>
                                <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">Founders, agencies, and OSS adopters can drop visitors straight into real blog, forum, and support flows—so pitches, proposals, and readme files point to live proof, not empty shells.</p>
                            </div>
                            <div class="flex flex-col gap-3">
                                <div class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">SSR, clean markup, and lightweight UI components keep marketing pages fast, indexable, and credible for anyone evaluating the starter.</div>
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
                                        <!-- Inline SVG icons — wrapper controls text color which icons inherit -->
                                        <CarouselItem
                                            v-for="icon in techIconsInline"
                                            :key="icon.name"
                                            class="pl-1 md:basis-1/4 lg:basis-1/5"
                                        >
                                            <div class="p-1">
                                                <Card class="bg-gradient-to-br from-[#fff7e6] via-[#f4f0e8] to-[#e8e5dc] dark:from-[#1d1c19] dark:via-[#171612] dark:to-[#11100d] text-[#1b1b18] dark:text-[#EDEDEC]">
                                                    <CardContent class="flex aspect-square items-center justify-center p-4">
                                                        <!-- wrapper sets the color; svg markup is injected and inherits currentColor -->
                                                        <div
                                                            class="tech-icon text-[#8b5a00] dark:text-[#f3d29e]"
                                                            v-html="icon.svg"
                                                            :aria-label="icon.name"
                                                            role="img"
                                                        />
                                                    </CardContent>
                                                </Card>
                                            </div>
                                        </CarouselItem>
                                    </CarouselContent>
                                    <CarouselPrevious class="text-[#1b1b18] dark:text-[#EDEDEC]" />
                                    <CarouselNext class="text-[#1b1b18] dark:text-[#EDEDEC]" />
                                </Carousel>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Storytelling-first layout</p>
                            <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Content, community, and support that prove the value</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">Walk prospects and contributors through the same journey your SaaS promises: read a post, jump into a moderated thread, open a support ticket, and see consistent UX without extra wiring.</p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div
                                v-if="websiteSections.blog"
                                class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]"
                            >
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Editorial</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Blog</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Publish announcements, guides, and release notes with SEO-friendly layouts that already feel launch-ready.
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
                                    Organized boards, thread subscriptions, and moderation tools so founders and clients see healthy discourse from day one.
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
                                    Ticketing, FAQs, and satisfaction surveys that plug into member accounts to show you’re ready to support paying users.
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
                        </div>
                    </section>

                    <section class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Revenue & retention</p>
                            <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Show the business side is already live</h2>
                            <p class="max-w-3xl text-sm text-[#706f6c] dark:text-[#A1A09A]">Stripe billing, dashboards, and admin controls are wired up so founders, agencies, and contributors can preview real monetization flows without building scaffolding first.</p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)] lg:col-span-2">
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Monetization</p>
                                <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-baseline sm:justify-between">
                                    <div>
                                        <h3 class="text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Pricing & Billing</h3>
                                        <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            Stripe-backed subscriptions, invoices, and webhooks with member-facing billing screens your stakeholders can click through today.
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
                                        <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Upgrade, cancel, resume, and retry payments directly from member settings so prospects see retention flows in motion.</p>
                                    </div>
                                    <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                        <p class="font-semibold">Invoices & Webhooks</p>
                                        <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Audit webhook deliveries and keep invoices aligned with your Stripe catalog before clients sign off.</p>
                                    </div>
                                    <div class="rounded-md bg-[#f9f7f2] p-4 text-sm text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                        <p class="font-semibold">Pricing Presets</p>
                                        <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">Start with common plan tiers and tailor the copy so your proposal shows pricing clarity immediately.</p>
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
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Operations</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Admin Control Panel</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Manage users, permissions, support queues, and moderation workflows inside the Inertia-powered ACP ready for stakeholder demos.
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
                                    Enable or disable the blog, forum, support center, billing, and social logins so you can curate a focused walkthrough for each audience.
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
                                    TOTP multi-factor authentication, recovery codes, session management, and OAuth identity linking live in member settings to reassure teams evaluating security.
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

                            <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)] lg:col-span-2">
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">API</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Docs & Tokens</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Versioned `/api/v1` endpoints, Swagger UI at <code>/api/docs</code>, and Sanctum token management so technical buyers and OSS contributors can verify integrations fast.
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
                        </div>
                    </section>

                    <section class="space-y-6">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Choose your path</p>
                            <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">MetaForge adoption options for every team</h2>
                            <p class="max-w-4xl text-sm text-[#706f6c] dark:text-[#A1A09A]">Whether you’re evaluating the open source starter, pitching a client, or requesting a licensed build, the page you’re viewing is the same boilerplate your stakeholders will experience.</p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-3">
                            <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Open Source</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Self-host & contribute</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Clone, run migrations, and start shipping—PR-friendly conventions and TypeScript-first components make it easy to extend and give back.</p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a
                                        href="https://github.com/MetaGrenade/laravel-vue"
                                        class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        View on GitHub
                                    </a>
                                </div>
                            </div>
                            <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Commercial</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Licensing & SLAs</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Secure a commercial license with upgrade paths, support coverage, and brand-safe defaults so your client or leadership team signs off quickly.</p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <Link
                                        :href="route('pricing')"
                                        class="inline-flex items-center rounded-sm bg-[#1b1b18] px-4 py-2 text-xs font-medium text-white transition hover:bg-[#11110f] dark:bg-white dark:text-[#0f0f0d] dark:hover:bg-[#f5f5f0]"
                                    >
                                        Explore Plans
                                    </Link>
                                </div>
                            </div>
                            <div class="rounded-lg bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] transition hover:shadow-[0_12px_40px_rgba(0,0,0,0.08)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] dark:hover:shadow-[0_12px_40px_rgba(0,0,0,0.45)]">
                                <p class="text-xs uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">Services</p>
                                <h3 class="mt-2 text-xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Custom builds & onboarding</h3>
                                <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Hand the project to us for bespoke flows, integrations, or white-label delivery—built on the same MetaForge codebase you see here.</p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a
                                        href="https://github.com/MetaGrenade/laravel-vue/issues/new/choose"
                                        class="inline-flex items-center rounded-sm border border-[#19140035] px-4 py-2 text-xs font-medium text-[#1b1b18] transition hover:border-[#1915014a] hover:bg-[#f7f7f3] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b] dark:hover:bg-[#1e1e1b]"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Request a build
                                    </a>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl bg-white p-8 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.06)] dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                        <div class="flex flex-col gap-6">
                            <div class="space-y-2">
                                <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Evaluation Playbook</p>
                                <h3 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Shorten your proof-of-value loop</h3>
                                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Use these steps to demo MetaForge to teammates, clients, or investors without rewriting copy or stitching together mock screens.</p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-4">
                                <div class="rounded-lg bg-[#f9f7f2] p-4 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">01</p>
                                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Spin up the demo account to preview the unified UX across blog, forum, billing, and admin.</p>
                                </div>
                                <div class="rounded-lg bg-[#f9f7f2] p-4 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">02</p>
                                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Clone the repo, install dependencies, and reuse the seeded content structure for your own messaging.</p>
                                </div>
                                <div class="rounded-lg bg-[#f9f7f2] p-4 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">03</p>
                                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Toggle blog, forum, and support sections via settings to match the story you’re presenting.</p>
                                </div>
                                <div class="rounded-lg bg-[#f9f7f2] p-4 text-[#1b1b18] dark:bg-[#1c1b17] dark:text-[#EDEDEC]">
                                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-[#8b5a00] dark:text-[#f3d29e]">04</p>
                                    <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Share staging links or screen recordings with stakeholders—every surface is consistent out of the box.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl bg-[#11110f] px-6 py-10 text-white bg-gradient-to-br from-[#fff7e6] via-[#f4f0e8] to-[#e8e5dc] shadow-[inset_0px_0px_0px_1px_rgba(255,255,255,0.06)] dark:from-[#1d1c19] dark:via-[#171612] dark:to-[#11100d] dark:text-[#EDEDEC]">
                        <div class="flex flex-col items-start gap-6 text-left sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-3">
                                <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Launch Faster</p>
                                <h3 class="text-2xl font-semibold leading-tight text-[#1b1b18] dark:text-[#EDEDEC]">Plug into <Anvil class="inline text-[#8b5a00] dark:text-[#f3d29e]" /> <span class="text-[#8b5a00] dark:text-[#f3d29e]">MetaForge</span> and ship your product story</h3>
                                <p class="max-w-2xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Every module uses the same typography, spacing, and components so founders, agencies, and OSS contributors can focus on content, customization, and onboarding instead of wiring basics together.
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
                                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">A concise overview of the frameworks, tooling, and references so evaluators can trust the stack before cloning.</p>
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

<style scoped>
/* Ensure every shape inside the inlined SVG uses currentColor (text color on wrapper).
   This forces single-color icons that follow the wrapper's text color.
   We use !important to override hard-coded fills/strokes that may be baked into the SVG.
*/
.tech-icon svg,
.tech-icon svg * {
    /* The wrapper .tech-icon sets the color via Tailwind classes
       (text-[#8b5a00] dark:text-[#f3d29e]) and the following forces svg elements to inherit. */
    fill: currentColor !important;
    stroke: currentColor !important;
}

/* Make sure the SVG scales nicely inside the card content */
.tech-icon svg {
    display: block; /* removes baseline gaps */
    max-height: 3rem; /* same sizing as inline style; this is defensive */
    width: auto;
}
</style>
