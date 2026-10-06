<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Braces,
    Check,
    ChevronDown,
    CreditCard,
    Gauge,
    LayoutDashboard,
    LifeBuoy,
    MessagesSquare,
    Newspaper,
    Search,
    ShieldCheck,
    ShoppingBag,
} from '@lucide/vue';
import { computed, type Component } from 'vue';

const page = usePage<SharedData>();

const websiteSections = computed(() => {
    const settings = page.props.settings?.website_sections;

    return {
        blog: settings?.blog ?? true,
        forum: settings?.forum ?? true,
        support: settings?.support ?? true,
        commerce: settings?.commerce ?? true,
    };
});

const canRegister = computed(() => route().has('register'));

/*
 * Tech logos are inlined as raw SVG so they inherit `currentColor` and can be
 * tinted to match the theme. They are bundled at build time (no requests).
 */
const rawIconModules = import.meta.glob<string>('../../images/tech-icons/*.svg', { query: '?raw', import: 'default', eager: true });

const prepareSvg = (rawSvg: string) =>
    rawSvg
        .replace(/<\?xml[\s\S]*?\?>/gi, '')
        .replace(/<!DOCTYPE[\s\S]*?>/gi, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<svg([^>]*)>/i, (_match, attrs: string) => {
            const cleaned = attrs.replace(/\s(width|height|style)=["'][^"']*["']/gi, '').trim();

            return `<svg ${cleaned} aria-hidden="true" focusable="false">`;
        })
        .replace(/\s+xmlns:[a-zA-Z]+=["'][^"']*["']/g, '')
        .trim();

const techIcons = Object.entries(rawIconModules)
    .map(([path, raw]) => ({
        name: path.split('/').pop()!.replace('.svg', ''),
        svg: prepareSvg(raw ?? ''),
    }))
    .filter((icon) => !['openai', 'docker', 'npm', 'pusher'].includes(icon.name))
    .sort((a, b) => a.name.localeCompare(b.name));

const highlights = ['Auth, MFA and social login', 'Stripe subscriptions and invoices', 'Role-based admin control panel'];

const features: { title: string; description: string; icon: Component }[] = [
    {
        title: 'Authentication & security',
        description: 'Email verification, TOTP two-factor auth, recovery codes, session management, OAuth linking and a strict CSP.',
        icon: ShieldCheck,
    },
    {
        title: 'Billing that works',
        description: 'Cashier-powered plans, trials, invoices and payment methods, with webhook auditing in the admin panel.',
        icon: CreditCard,
    },
    {
        title: 'Admin control panel',
        description: 'Users, roles and permissions, moderation queues, system settings and analytics behind fine-grained access.',
        icon: LayoutDashboard,
    },
    {
        title: 'Publishing',
        description: 'A rich-text blog with categories, tags, scheduling, revisions, comments and an RSS feed.',
        icon: Newspaper,
    },
    {
        title: 'Community',
        description: 'Forums with boards, subscriptions, mentions, reactions, polls, reputation badges and reporting tools.',
        icon: MessagesSquare,
    },
    {
        title: 'Support desk',
        description: 'Tickets with SLAs, assignment rules, canned replies, teams, an FAQ and satisfaction ratings.',
        icon: LifeBuoy,
    },
    {
        title: 'Search & SEO',
        description: 'Server-side rendering, structured data, sitemaps and a site-wide command palette search.',
        icon: Search,
    },
    {
        title: 'API ready',
        description: 'Versioned REST endpoints with Sanctum tokens, rate limiting and interactive API documentation.',
        icon: Braces,
    },
    {
        title: 'Fast by default',
        description: 'Vite 8, code-split pages, self-hosted fonts and a lightweight UI that keeps pages quick to load.',
        icon: Gauge,
    },
];

const modules = computed(() =>
    [
        {
            key: 'blog',
            title: 'Blog',
            description: 'Announcements, guides and release notes with SEO-friendly article pages.',
            href: route('blogs.index'),
            cta: 'Read the blog',
            icon: Newspaper,
            enabled: websiteSections.value.blog,
        },
        {
            key: 'forum',
            title: 'Forum',
            description: 'Organised boards, thread subscriptions and moderation tools for a healthy community.',
            href: route('forum.index'),
            cta: 'Browse threads',
            icon: MessagesSquare,
            enabled: websiteSections.value.forum,
        },
        {
            key: 'commerce',
            title: 'Shop',
            description: 'Product catalogue, detail pages and a cart you can extend into a full storefront.',
            href: route('shop.index'),
            cta: 'Visit the shop',
            icon: ShoppingBag,
            enabled: websiteSections.value.commerce,
        },
        {
            key: 'support',
            title: 'Support',
            description: 'Help centre with FAQs and ticketing connected to member accounts.',
            href: route('support'),
            cta: 'Open support',
            icon: LifeBuoy,
            enabled: websiteSections.value.support,
        },
    ].filter((module) => module.enabled),
);

const steps = [
    { title: 'Create a demo account', description: 'Explore the member experience across the blog, forum, billing and settings.' },
    { title: 'Clone the repository', description: 'Install dependencies, run the migrations and seeders, and start the dev server.' },
    { title: 'Toggle the modules', description: 'Enable only the sections you need from the admin system settings.' },
    { title: 'Make it yours', description: 'Change the brand colour in one place, replace the copy and start shipping features.' },
];

const stack = [
    {
        title: 'Frontend',
        items: ['Vue 3 with TypeScript', 'Inertia 3 with SSR', 'Tailwind CSS 4 and shadcn-vue', 'Vite 8'],
    },
    {
        title: 'Backend',
        items: ['Laravel 13', 'Cashier (Stripe) and Sanctum', 'Spatie permissions', 'Queues, events and broadcasting'],
    },
    {
        title: 'Requirements',
        items: ['PHP 8.4+ with Composer', 'Node.js 24+', 'MySQL, PostgreSQL or SQLite', 'Optional Pusher or Reverb for realtime'],
    },
];

const resources = [
    { title: 'Laravel 13 docs', href: 'https://laravel.com/docs/13.x' },
    { title: 'Inertia 3 docs', href: 'https://inertiajs.com/docs/v3/getting-started/index' },
    { title: 'Vue 3 guide', href: 'https://vuejs.org/guide/introduction.html' },
    { title: 'Tailwind CSS docs', href: 'https://tailwindcss.com/docs' },
];

const faqs = [
    {
        question: 'Is this production-ready?',
        answer: 'Yes. It ships with CI, a large automated test suite, hardened security headers, rate limiting and sanitised user content. Review the configuration for your own infrastructure before launch.',
    },
    {
        question: 'Can I use a different payment provider?',
        answer: 'Stripe is built in through Laravel Cashier. Billing logic is isolated in its own module, so you can swap or add providers.',
    },
    {
        question: 'Can I turn off modules I do not need?',
        answer: 'Yes. The blog, forum, support centre and shop can each be disabled from the admin system settings, which also hides their navigation.',
    },
    {
        question: 'How do I change the look and feel?',
        answer: 'All colours are design tokens in resources/css/app.css. Change the primary colour there and every component, chart and focus ring follows, in light and dark mode.',
    },
];
</script>

<template>
    <AppLayout full-width>
        <!-- Description, canonical and social tags are set server-side (HomeController). -->
        <Head title="Laravel Vue Starter Kit — Production-ready Boilerplate for SaaS" />

        <!-- Hero -->
        <section class="relative overflow-hidden border-b bg-background">
            <!-- Backdrop: fading grid plus two slowly drifting colour glows (plain gradients, no blur). -->
            <div class="pointer-events-none absolute inset-0 bg-grid [mask-image:radial-gradient(ellipse_at_50%_0%,black,transparent_72%)]" />
            <div
                class="pointer-events-none absolute -top-40 -left-32 size-[34rem] animate-drift rounded-full bg-[radial-gradient(closest-side,hsl(var(--primary)/0.16),transparent)]"
            />
            <div
                class="pointer-events-none absolute -top-24 right-[-10rem] size-[30rem] animate-drift rounded-full bg-[radial-gradient(closest-side,hsl(var(--highlight)/0.2),transparent)] [animation-delay:-9s]"
            />

            <div class="relative container-app grid items-center gap-12 py-16 sm:py-20 lg:grid-cols-[1.05fr_1fr] lg:py-24">
                <div>
                    <a
                        href="https://github.com/MetaGrenade/laravel-vue"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex animate-rise items-center gap-2 rounded-full border bg-card px-3 py-1 text-xs font-medium text-muted-foreground shadow-xs transition-colors hover:text-foreground"
                    >
                        <span class="size-1.5 rounded-full bg-highlight" />
                        Open source · Laravel 13 + Vue 3
                        <ArrowRight class="size-3" />
                    </a>

                    <h1
                        class="mt-6 animate-rise text-4xl font-semibold tracking-tight sm:text-5xl lg:text-[3.4rem] lg:leading-[1.08]"
                        style="--delay: 80ms"
                    >
                        The production-ready starter kit for
                        <span class="bg-[linear-gradient(transparent_78%,hsl(var(--highlight)/0.55)_78%)] text-primary">SaaS and communities</span>
                    </h1>
                    <p class="mt-5 max-w-xl animate-rise text-lg text-muted-foreground" style="--delay: 160ms">
                        Launch faster with authentication, billing, an admin panel, content and community features already wired together with
                        Laravel, Inertia, Vue and Tailwind.
                    </p>

                    <div class="mt-8 flex animate-rise flex-wrap gap-3" style="--delay: 240ms">
                        <Button v-if="canRegister" size="lg" as-child>
                            <Link :href="route('register')">
                                Create a demo account
                                <ArrowRight />
                            </Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <Link :href="route('pricing')">View pricing</Link>
                        </Button>
                    </div>

                    <ul class="mt-8 grid animate-rise gap-2 text-sm text-muted-foreground sm:grid-cols-2" style="--delay: 320ms">
                        <li v-for="item in highlights" :key="item" class="flex items-center gap-2">
                            <Check class="size-4 text-success" />
                            {{ item }}
                        </li>
                    </ul>
                </div>

                <!-- Product preview: plain markup, no images -->
                <div class="relative animate-appear" style="--delay: 200ms" aria-hidden="true">
                    <div class="relative animate-float">
                        <!-- Offset tile behind the card for depth -->
                        <div class="absolute inset-0 translate-x-3 translate-y-3 rounded-xl border bg-stripes" />
                        <div class="relative overflow-hidden rounded-xl border bg-card shadow-xl shadow-black/5 dark:shadow-black/40">
                            <div class="flex items-center gap-1.5 border-b bg-muted/50 px-4 py-3">
                                <span class="size-2.5 rounded-full bg-foreground/15" />
                                <span class="size-2.5 rounded-full bg-foreground/15" />
                                <span class="size-2.5 rounded-full bg-foreground/15" />
                                <span class="ml-3 h-5 flex-1 rounded-md bg-background" />
                            </div>
                            <div class="grid grid-cols-[7.5rem_1fr]">
                                <div class="space-y-2 border-r p-4">
                                    <div class="h-2 w-14 rounded-full bg-primary/70" />
                                    <div v-for="n in 6" :key="n" class="h-2 rounded-full bg-muted" :class="n % 2 ? 'w-16' : 'w-12'" />
                                </div>
                                <div class="space-y-4 p-5">
                                    <div class="grid grid-cols-3 gap-3">
                                        <div v-for="(stat, i) in ['$48.2k', '2,315', '98.4%']" :key="stat" class="rounded-lg border p-3">
                                            <div class="h-1.5 w-10 rounded-full bg-muted" />
                                            <p class="mt-2 text-sm font-semibold tabular-nums">{{ stat }}</p>
                                            <p class="mt-1 text-[0.65rem] font-medium" :class="i === 2 ? 'text-muted-foreground' : 'text-success'">
                                                {{ i === 2 ? 'uptime' : '+12.5%' }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="rounded-lg border p-4">
                                        <div class="h-1.5 w-20 rounded-full bg-muted" />
                                        <div class="mt-4 flex h-28 items-end gap-2">
                                            <div
                                                v-for="(h, i) in [35, 52, 44, 63, 58, 72, 66, 84, 78, 92]"
                                                :key="i"
                                                class="flex-1 animate-grow rounded-t-sm"
                                                :class="i === 9 ? 'bg-highlight' : 'bg-primary/30'"
                                                :style="{ height: `${h}%`, '--delay': `${450 + i * 70}ms` }"
                                            />
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <div v-for="n in 3" :key="n" class="flex items-center gap-3">
                                            <span class="size-6 rounded-full bg-muted" />
                                            <span class="h-2 flex-1 rounded-full bg-muted" />
                                            <span class="h-4 w-12 rounded-full" :class="n === 1 ? 'bg-success/20' : 'bg-muted'" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Floating notification chip -->
                        <div
                            class="absolute -bottom-5 -left-4 hidden animate-rise items-center gap-3 rounded-xl border bg-card px-4 py-3 shadow-lg shadow-black/5 sm:flex dark:shadow-black/40"
                            style="--delay: 1100ms"
                        >
                            <span class="flex size-8 items-center justify-center rounded-full bg-highlight text-highlight-foreground">
                                <CreditCard class="size-4" />
                            </span>
                            <div>
                                <p class="text-xs font-semibold">New subscription</p>
                                <p class="text-[0.7rem] text-muted-foreground">Pro plan · just now</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stack strip -->
        <section class="relative border-b bg-surface" aria-labelledby="stack-heading">
            <div
                class="pointer-events-none absolute inset-0 bg-stripes [mask-image:linear-gradient(to_right,transparent,black_30%,black_70%,transparent)]"
            />
            <div class="relative container-app py-10">
                <h2 id="stack-heading" class="text-center text-sm font-medium text-muted-foreground">Built on a modern, well-supported stack</h2>
                <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-10 gap-y-6">
                    <li
                        v-for="icon in techIcons"
                        :key="icon.name"
                        class="tech-icon text-muted-foreground/70 transition-colors hover:text-foreground"
                        :title="icon.name"
                    >
                        <span class="sr-only">{{ icon.name }}</span>
                        <span v-html="icon.svg" />
                    </li>
                </ul>
            </div>
        </section>

        <!-- Features -->
        <section class="relative overflow-hidden bg-background bg-wash-primary" aria-labelledby="features-heading">
            <div class="pointer-events-none absolute inset-0 bg-dots [mask-image:linear-gradient(to_bottom,black,transparent_55%)]" />
            <div class="relative container-app py-20 lg:py-24">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <p class="eyebrow">Everything included</p>
                    <h2 id="features-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Skip the plumbing, ship the product</h2>
                    <p class="mt-4 text-muted-foreground">
                        Opinionated defaults and tested building blocks for the parts every SaaS and community platform needs.
                    </p>
                </div>

                <div class="reveal mt-14 grid gap-px overflow-hidden rounded-xl border bg-border shadow-xs sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="(feature, index) in features" :key="feature.title" class="group bg-card p-6 transition-colors hover:bg-muted/40">
                        <span
                            class="flex size-9 items-center justify-center rounded-lg transition-transform group-hover:-translate-y-0.5"
                            :class="index % 3 === 1 ? 'bg-highlight/25 text-foreground' : 'bg-primary/10 text-primary'"
                        >
                            <component :is="feature.icon" class="size-[1.1rem]" />
                        </span>
                        <h3 class="mt-4 font-semibold">{{ feature.title }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">{{ feature.description }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Live modules -->
        <section v-if="modules.length" class="relative overflow-hidden border-y bg-surface bg-wash-highlight" aria-labelledby="modules-heading">
            <div class="pointer-events-none absolute inset-0 bg-grid [mask-image:linear-gradient(to_bottom,transparent,black_40%,transparent)]" />
            <div class="relative container-app py-20 lg:py-24">
                <div class="reveal flex flex-col justify-between gap-4 md:flex-row md:items-end">
                    <div class="max-w-2xl">
                        <p class="eyebrow">Live demo</p>
                        <h2 id="modules-heading" class="mt-3 text-3xl font-semibold tracking-tight">See every module in action</h2>
                        <p class="mt-4 text-muted-foreground">These are the real pages your users get, not mock-ups. Click through and try them.</p>
                    </div>
                    <Button variant="outline" class="bg-card" as-child>
                        <Link :href="route('dashboard')">
                            Open the dashboard
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2" :class="modules.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4'">
                    <Link
                        v-for="module in modules"
                        :key="module.key"
                        :href="module.href"
                        class="group reveal flex flex-col rounded-xl border bg-card p-6 shadow-xs transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                    >
                        <span class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <component :is="module.icon" class="size-5" />
                        </span>
                        <h3 class="mt-4 font-semibold">{{ module.title }}</h3>
                        <p class="mt-1.5 flex-1 text-sm text-muted-foreground">{{ module.description }}</p>
                        <span class="mt-5 inline-flex items-center gap-1 text-sm font-medium text-primary">
                            {{ module.cta }}
                            <ArrowRight class="size-4 transition-transform group-hover:translate-x-0.5" />
                        </span>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Getting started -->
        <section class="bg-background" aria-labelledby="steps-heading">
            <div class="container-app py-20 lg:py-24">
                <div class="reveal max-w-2xl">
                    <p class="eyebrow">Getting started</p>
                    <h2 id="steps-heading" class="mt-3 text-3xl font-semibold tracking-tight">From clone to launch in four steps</h2>
                </div>
                <ol class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(step, index) in steps" :key="step.title" class="reveal relative rounded-xl border bg-card p-5 shadow-xs">
                        <span
                            class="flex size-7 items-center justify-center rounded-full bg-highlight text-sm font-semibold text-highlight-foreground tabular-nums"
                        >
                            {{ index + 1 }}
                        </span>
                        <h3 class="mt-4 font-semibold">{{ step.title }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">{{ step.description }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Stack details -->
        <section class="relative overflow-hidden border-y bg-surface" aria-labelledby="tech-heading">
            <div class="pointer-events-none absolute inset-0 bg-stripes [mask-image:linear-gradient(to_bottom_right,black,transparent_60%)]" />
            <div class="relative container-app grid gap-12 py-20 lg:grid-cols-[1fr_2fr] lg:py-24">
                <div class="reveal">
                    <p class="eyebrow">Tech stack</p>
                    <h2 id="tech-heading" class="mt-3 text-3xl font-semibold tracking-tight">Modern tooling, no surprises</h2>
                    <p class="mt-4 text-muted-foreground">
                        Current releases across the stack, with Pint, ESLint, Prettier, type checking and PHPUnit running in CI on every pull request.
                    </p>
                    <ul class="mt-6 space-y-2">
                        <li v-for="resource in resources" :key="resource.href">
                            <a
                                :href="resource.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {{ resource.title }}
                                <ArrowUpRight class="size-3.5" />
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div v-for="group in stack" :key="group.title" class="reveal rounded-xl border bg-card p-6 shadow-xs">
                        <h3 class="font-semibold">{{ group.title }}</h3>
                        <ul class="mt-4 space-y-2.5">
                            <li v-for="item in group.items" :key="item" class="flex gap-2 text-sm text-muted-foreground">
                                <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section class="bg-background" aria-labelledby="faq-heading">
            <div class="container-app grid gap-12 py-20 lg:grid-cols-[1fr_2fr] lg:py-24">
                <div class="reveal">
                    <p class="eyebrow">FAQ</p>
                    <h2 id="faq-heading" class="mt-3 text-3xl font-semibold tracking-tight">Frequently asked questions</h2>
                </div>
                <div class="reveal divide-y border-y">
                    <details v-for="faq in faqs" :key="faq.question" class="group py-5">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium [&::-webkit-details-marker]:hidden"
                        >
                            {{ faq.question }}
                            <ChevronDown class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180" />
                        </summary>
                        <p class="mt-3 text-sm text-muted-foreground">{{ faq.answer }}</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- Call to action -->
        <section class="bg-background pb-20 lg:pb-24">
            <div class="container-app">
                <div
                    class="reveal relative overflow-hidden rounded-2xl bg-brand-gradient px-6 py-14 text-center text-primary-foreground sm:px-12 dark:border dark:border-primary/25 dark:bg-primary/10 dark:bg-none dark:text-foreground"
                >
                    <div
                        class="pointer-events-none absolute inset-0 bg-grid [mask-image:radial-gradient(ellipse_at_center,black,transparent_75%)] opacity-60 dark:opacity-100"
                    />
                    <div
                        class="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-[radial-gradient(closest-side,hsl(var(--highlight)/0.5),transparent)]"
                    />
                    <div class="relative">
                        <h2 class="text-3xl font-semibold tracking-tight">Start building your product today</h2>
                        <p class="mx-auto mt-4 max-w-xl text-primary-foreground/80 dark:text-muted-foreground">
                            Everything you need to launch, with the freedom to change anything. Free and open source.
                        </p>
                        <div class="mt-8 flex flex-wrap justify-center gap-3">
                            <Button v-if="canRegister" size="lg" variant="highlight" as-child>
                                <Link :href="route('register')">Get started free</Link>
                            </Button>
                            <Button
                                size="lg"
                                variant="ghost"
                                class="text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground dark:text-foreground dark:hover:bg-accent dark:hover:text-foreground"
                                as-child
                            >
                                <a href="https://github.com/MetaGrenade/laravel-vue" target="_blank" rel="noopener noreferrer">
                                    View on GitHub
                                    <ArrowUpRight />
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
/* Force single-colour logos that follow the wrapper's text colour. */
.tech-icon :deep(svg) {
    display: block;
    height: 1.75rem;
    width: auto;
}

.tech-icon :deep(svg),
.tech-icon :deep(svg *) {
    fill: currentColor !important;
    stroke: currentColor !important;
}
</style>
