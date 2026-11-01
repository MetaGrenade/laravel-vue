<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import ForgeHorizon from '@/components/ForgeHorizon.vue';
import ProductPreview from '@/components/marketing/ProductPreview.vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Braces,
    ChevronDown,
    CreditCard,
    FolderGit2,
    KeyRound,
    LayoutDashboard,
    LifeBuoy,
    MessagesSquare,
    Moon,
    Newspaper,
    Package,
    Search,
    ShieldCheck,
    ShoppingBag,
    Sun,
} from '@lucide/vue';
import { computed, type Component } from 'vue';

const page = usePage<SharedData>();
const { updateAppearance } = useAppearance();

const toggleAppearance = () => {
    updateAppearance(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
};

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

const stackPills = computed(() => {
    const pills: { label: string; href?: string; tone: string; icon?: Component }[] = [
        {
            label: 'Laravel 13',
            tone: 'bg-rose-100 text-rose-900 dark:bg-rose-400/30 dark:text-rose-50 dark:ring-1 dark:ring-inset dark:ring-rose-300/45',
        },
        {
            label: 'Vue 3',
            tone: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-400/30 dark:text-emerald-50 dark:ring-1 dark:ring-inset dark:ring-emerald-300/45',
        },
        { label: 'Inertia.js', tone: 'bg-sky-100 text-sky-900 dark:bg-sky-400/30 dark:text-sky-50 dark:ring-1 dark:ring-inset dark:ring-sky-300/45' },
        {
            label: 'TypeScript',
            tone: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-400/32 dark:text-indigo-50 dark:ring-1 dark:ring-inset dark:ring-indigo-300/50',
        },
        { label: 'Stripe', href: route('pricing'), tone: 'border bg-card text-foreground' },
        { label: 'Admin dashboard', tone: 'border bg-card text-foreground' },
    ];

    if (websiteSections.value.forum) {
        pills.push({ label: 'Forum', href: route('forum.index'), tone: 'border bg-card text-foreground' });
    }

    if (websiteSections.value.support) {
        pills.push({ label: 'Support center', href: route('support'), tone: 'border bg-card text-foreground' });
    }

    if (websiteSections.value.commerce) {
        pills.push({ label: 'Shop', href: route('shop.index'), tone: 'border bg-card text-foreground' });
    }

    return pills;
});

const features: { title: string; description: string; icon: Component; tone: string }[] = [
    {
        title: 'Shop and checkout',
        description:
            'Catalogue, variants, stock, shipping and tax, Stripe Checkout, guest orders and Tebex packages or the experimental Checkout API.',
        icon: ShoppingBag,
        tone: 'bg-[hsl(346_72%_46%/0.12)] text-[hsl(346_72%_32%)] dark:text-[hsl(346_85%_76%)]',
    },
    {
        title: 'Subscriptions and entitlements',
        description: 'Cashier plans with trials and dunning, plus feature and limit entitlements from plans and Tebex packages.',
        icon: CreditCard,
        tone: 'bg-highlight/25 text-[hsl(36_80%_28%)] dark:bg-highlight/15 dark:text-[hsl(40_92%_72%)]',
    },
    {
        title: 'Community and moderation',
        description: 'Forums with prefixes, solutions, attachments and ignores, member follows and DMs, automod, bans and one-click content cleanup.',
        icon: MessagesSquare,
        tone: 'bg-primary/10 text-primary',
    },
    {
        title: 'Authentication and keys',
        description: 'Email verification, TOTP two-factor auth, recovery codes, OAuth linking and scoped Sanctum API keys with rotation.',
        icon: KeyRound,
        tone: 'bg-[hsl(221_75%_48%/0.12)] text-[hsl(221_70%_32%)] dark:text-[hsl(221_90%_78%)]',
    },
    {
        title: 'Security by default',
        description:
            'Nonce CSP and security headers checked in CI, private disks for member uploads, webhook replay protection and a published security contact.',
        icon: ShieldCheck,
        tone: 'bg-success/15 text-[hsl(152_64%_24%)] dark:text-success',
    },
    {
        title: 'Admin control panel',
        description: 'Named permissions for users, blogs, support, commerce and billing, plus audit log and read-only impersonation.',
        icon: LayoutDashboard,
        tone: 'bg-[hsl(270_55%_52%/0.12)] text-[hsl(270_45%_36%)] dark:text-[hsl(270_85%_80%)]',
    },
    {
        title: 'Publishing and support',
        description: 'Blog with WebP covers, scheduling and RSS; tickets with SLAs, private attachments and a help centre FAQ.',
        icon: Newspaper,
        tone: 'bg-[hsl(18_80%_48%/0.12)] text-[hsl(18_75%_32%)] dark:text-[hsl(18_90%_74%)]',
    },
    {
        title: 'Search and SEO',
        description:
            'Site-wide search, SSR, structured data and sitemaps. Meilisearch index rules, settings sync and reindex are ready for the engine path.',
        icon: Search,
        tone: 'bg-[hsl(187_65%_36%/0.12)] text-[hsl(187_70%_24%)] dark:text-[hsl(187_65%_68%)]',
    },
    {
        title: 'API and delivery',
        description: 'Versioned REST with scoped tokens, OpenAPI docs, queues, CI on MySQL and PostgreSQL, and accessibility checks on main flows.',
        icon: Braces,
        tone: 'bg-[hsl(322_60%_48%/0.12)] text-[hsl(322_55%_32%)] dark:text-[hsl(322_80%_78%)]',
    },
];

const modules = computed(() =>
    [
        {
            key: 'commerce',
            title: 'Shop',
            description: 'Buyable catalogue, cart, checkout with Stripe or Tebex, orders, refunds and digital downloads.',
            href: route('shop.index'),
            cta: 'Visit the shop',
            icon: Package,
            enabled: websiteSections.value.commerce,
        },
        {
            key: 'forum',
            title: 'Forum',
            description: 'Boards, solved answers, attachments, subscriptions and tools to clear out a spam wave.',
            href: route('forum.index'),
            cta: 'Browse threads',
            icon: MessagesSquare,
            enabled: websiteSections.value.forum,
        },
        {
            key: 'blog',
            title: 'Blog',
            description: 'Guides and release notes with scheduling, revisions, comments and an RSS feed.',
            href: route('blogs.index'),
            cta: 'Read the blog',
            icon: Newspaper,
            enabled: websiteSections.value.blog,
        },
        {
            key: 'support',
            title: 'Support',
            description: 'Tickets with SLAs, private file uploads, canned replies and an FAQ that members can rate.',
            href: route('support'),
            cta: 'Open support',
            icon: LifeBuoy,
            enabled: websiteSections.value.support,
        },
    ].filter((module) => module.enabled),
);

const steps = [
    { title: 'Create a demo account', description: 'Walk the shop, forum, billing portal and settings as a member would.' },
    { title: 'Clone the repository', description: 'Install dependencies, run migrations and seeders, then start the Herd or compose stack.' },
    { title: 'Toggle the modules', description: 'Turn blog, forum, support and commerce on or off from system settings.' },
    { title: 'Make it yours', description: 'Set APP_NAME, brand colour and logos, then ship the features that are already wired.' },
];

const stack = [
    {
        title: 'Frontend',
        items: ['Vue 3 with TypeScript', 'Inertia 3 with SSR', 'Tailwind CSS 4 and shadcn-vue', 'Vite 8'],
    },
    {
        title: 'Backend',
        items: ['Laravel 13', 'Cashier, Scout and Sanctum', 'Stripe and Tebex payments', 'Spatie permissions'],
    },
    {
        title: 'Requirements',
        items: ['PHP 8.4+ with Composer', 'Node.js 24+', 'MySQL, PostgreSQL or SQLite', 'Optional Redis, Meilisearch and Reverb'],
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
        answer: 'Yes. CI runs the suite on SQLite, MySQL and PostgreSQL, axe checks the main public flows, security headers and secrets scans run on every pull request, and uploads stay on private disks. Review the configuration for your own infrastructure before launch.',
    },
    {
        question: 'Which payment providers are supported?',
        answer: 'Stripe powers shop checkout and Cashier subscriptions. Tebex covers game-store packages, embedded checkout and an experimental Checkout API for your own catalogue. Both share the same payment and webhook layer.',
    },
    {
        question: 'Can I turn off modules I do not need?',
        answer: 'Yes. The blog, forum, support centre and shop can each be disabled from the admin system settings, which also hides their navigation.',
    },
    {
        question: 'How do I change the look and feel?',
        answer: 'Swap the wordmarks under public/brand, set APP_NAME, and change the primary colour in resources/css/app.css. Components, charts and focus rings follow in light and dark mode.',
    },
];
</script>

<template>
    <AppLayout full-width>
        <!-- Title, description, canonical and social tags are set server-side (HomeController). -->

        <!-- Hero -->
        <section class="relative overflow-hidden border-b">
            <div
                class="pointer-events-none absolute inset-0 bg-dots [mask-image:radial-gradient(ellipse_at_80%_0%,black,transparent_70%)] opacity-70"
            />
            <div class="pointer-events-none absolute inset-0 bg-hero-wash" aria-hidden="true" />
            <ForgeHorizon class="absolute inset-x-0 bottom-0 h-40 sm:h-52" />

            <div class="relative container-app grid items-center gap-12 py-16 sm:py-20 lg:grid-cols-[0.92fr_1.08fr] lg:py-24">
                <div>
                    <div class="animate-rise">
                        <AppLogo size="xl" />
                    </div>

                    <h1
                        class="mt-6 max-w-xl animate-rise text-4xl font-semibold tracking-tight sm:text-5xl lg:text-[3.25rem] lg:leading-[1.05]"
                        style="--delay: 80ms"
                    >
                        Laravel + Vue SaaS Boilerplate
                    </h1>
                    <p class="mt-5 max-w-xl animate-rise text-lg text-muted-foreground" style="--delay: 140ms">
                        Ship faster with auth, RBAC, billing, admin tools, and realtime features built in.
                    </p>

                    <div class="mt-8 flex animate-rise flex-wrap gap-3" style="--delay: 200ms">
                        <Button v-if="canRegister" size="lg" as-child>
                            <Link :href="route('register')">
                                Create a demo account
                                <ArrowRight />
                            </Link>
                        </Button>
                        <Button size="lg" variant="outline" class="bg-card" as-child>
                            <a href="https://github.com/MetaGrenade/laravel-vue" target="_blank" rel="noopener noreferrer">
                                <FolderGit2 />
                                Repository
                            </a>
                        </Button>
                        <Button v-if="!websiteSections.commerce" size="lg" variant="outline" class="bg-card" as-child>
                            <Link :href="route('pricing')">View pricing</Link>
                        </Button>
                    </div>

                    <ul class="mt-6 flex max-w-xl animate-rise flex-wrap gap-2" style="--delay: 260ms">
                        <li v-for="pill in stackPills" :key="pill.label">
                            <Link
                                v-if="pill.href"
                                :href="pill.href"
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition-colors hover:border-primary/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="pill.tone"
                            >
                                {{ pill.label }}
                            </Link>
                            <span v-else class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold" :class="pill.tone">
                                <component :is="pill.icon" v-if="pill.icon" class="size-3.5" />
                                {{ pill.label }}
                            </span>
                        </li>
                        <li>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border bg-card px-3 py-1 text-xs font-semibold text-foreground transition-colors hover:border-primary/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                @click="toggleAppearance"
                            >
                                <Moon class="size-3.5 dark:hidden" />
                                <Sun class="hidden size-3.5 dark:block" />
                                <span class="dark:hidden">Dark mode</span>
                                <span class="hidden dark:inline">Light mode</span>
                            </button>
                        </li>
                    </ul>

                    <p class="mt-8 flex animate-rise items-start gap-3" style="--delay: 320ms">
                        <span class="mt-0.5 h-9 w-0.5 shrink-0 rounded-full bg-highlight" aria-hidden="true" />
                        <span class="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            A full-stack starter kit<br />
                            for modern SaaS and community apps
                        </span>
                    </p>
                </div>

                <div class="relative z-10 animate-appear lg:translate-y-2" style="--delay: 180ms">
                    <ProductPreview />
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
        <section class="relative overflow-hidden bg-background bg-wash-primary cv-auto" aria-labelledby="features-heading">
            <div class="pointer-events-none absolute inset-0 bg-dots [mask-image:linear-gradient(to_bottom,black,transparent_55%)]" />
            <div class="relative container-app py-20 lg:py-24">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <p class="eyebrow">Everything included</p>
                    <h2 id="features-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                        Built for the product you are shipping
                    </h2>
                    <p class="mt-4 text-muted-foreground">
                        Commerce, community, billing and operations that grew with the kit—not placeholders you have to replace later.
                    </p>
                </div>

                <div class="reveal mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-xl border bg-card p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary/30"
                    >
                        <div class="flex items-center gap-2.5">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg" :class="feature.tone">
                                <component :is="feature.icon" class="size-3.5" />
                            </span>
                            <h3 class="font-semibold">{{ feature.title }}</h3>
                        </div>
                        <p class="mt-2.5 text-sm text-muted-foreground">{{ feature.description }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Live modules -->
        <section
            v-if="modules.length"
            class="relative overflow-hidden border-y bg-surface bg-wash-success cv-auto"
            aria-labelledby="modules-heading"
        >
            <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-success/80 to-transparent" />
            <div class="pointer-events-none absolute inset-0 bg-grid [mask-image:linear-gradient(to_bottom,transparent,black_40%,transparent)]" />
            <div class="relative container-app py-20 lg:py-24">
                <div class="reveal flex flex-col justify-between gap-4 md:flex-row md:items-end">
                    <div class="max-w-2xl">
                        <p class="eyebrow inline-flex items-center gap-2 text-[hsl(152_64%_24%)] dark:text-success">
                            <span class="size-1.5 rounded-full bg-success" aria-hidden="true" />
                            Live demo
                        </p>
                        <h2 id="modules-heading" class="mt-3 text-3xl font-semibold tracking-tight">See every module in action</h2>
                        <p class="mt-4 max-w-xl text-muted-foreground">
                            These are the real pages your users get, not mock-ups. Click through and try them.
                        </p>
                    </div>
                    <Button variant="outline" class="bg-card" as-child>
                        <Link :href="route('dashboard')">
                            Open the dashboard
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>

                <div class="reveal mt-10 grid gap-4 sm:grid-cols-2" :class="modules.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4'">
                    <Link
                        v-for="module in modules"
                        :key="module.key"
                        :href="module.href"
                        class="group relative flex flex-col overflow-hidden rounded-xl border bg-card p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-success/40 hover:shadow-md dark:shadow-none"
                    >
                        <span class="absolute inset-x-0 top-0 h-0.5 bg-success/75" aria-hidden="true" />
                        <div class="flex items-center gap-2.5">
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-success/15 text-[hsl(152_64%_24%)] dark:text-success"
                            >
                                <component :is="module.icon" class="size-3.5" />
                            </span>
                            <h3 class="font-semibold">{{ module.title }}</h3>
                        </div>
                        <p class="mt-2.5 flex-1 text-sm text-muted-foreground">{{ module.description }}</p>
                        <span class="mt-5 inline-flex items-center gap-1 text-sm font-medium text-primary">
                            {{ module.cta }}
                            <ArrowRight class="size-4 transition-transform group-hover:translate-x-0.5" />
                        </span>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Getting started -->
        <section class="bg-background cv-auto" aria-labelledby="steps-heading">
            <div class="container-app py-20 lg:py-24">
                <div class="reveal max-w-2xl">
                    <p class="eyebrow">Getting started</p>
                    <h2 id="steps-heading" class="mt-3 text-3xl font-semibold tracking-tight">From clone to launch in four steps</h2>
                </div>
                <ol class="reveal mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(step, index) in steps" :key="step.title" class="relative rounded-xl border bg-card p-5 shadow-xs">
                        <div class="flex items-center gap-2.5">
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-highlight text-sm font-semibold text-highlight-foreground tabular-nums"
                            >
                                {{ index + 1 }}
                            </span>
                            <h3 class="font-semibold">{{ step.title }}</h3>
                        </div>
                        <p class="mt-2.5 text-sm text-muted-foreground">{{ step.description }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Stack details -->
        <section class="relative overflow-hidden border-y bg-surface bg-wash-destructive cv-auto" aria-labelledby="tech-heading">
            <div class="pointer-events-none absolute inset-0 bg-stripes [mask-image:linear-gradient(to_bottom_right,black,transparent_60%)]" />
            <div class="relative container-app grid gap-12 py-20 lg:grid-cols-[1fr_2fr] lg:py-24">
                <div class="reveal flex gap-4">
                    <span class="mt-1 hidden w-0.5 shrink-0 self-stretch rounded-full bg-destructive/80 sm:block" aria-hidden="true" />
                    <div>
                        <p class="eyebrow inline-flex items-center gap-2 text-[hsl(0_70%_32%)] dark:text-[hsl(0_80%_74%)]">
                            <span class="size-1.5 rotate-45 bg-destructive" aria-hidden="true" />
                            Tech stack
                        </p>
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
                                    class="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground transition-colors hover:text-[hsl(0_70%_32%)] dark:hover:text-[hsl(0_80%_74%)]"
                                >
                                    {{ resource.title }}
                                    <ArrowUpRight class="size-3.5" />
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="reveal grid gap-4 sm:grid-cols-3">
                    <div v-for="group in stack" :key="group.title" class="overflow-hidden rounded-xl border bg-card shadow-sm dark:shadow-none">
                        <div
                            class="border-b border-destructive/15 bg-[linear-gradient(180deg,hsl(var(--destructive)/0.14),hsl(var(--destructive)/0.04))] px-5 py-3.5 text-[hsl(0_70%_32%)] dark:text-[hsl(0_80%_74%)]"
                        >
                            <h3 class="font-semibold">{{ group.title }}</h3>
                            <span class="mt-2 block h-0.5 w-8 rounded-full bg-current" aria-hidden="true" />
                        </div>
                        <ul class="space-y-2.5 px-5 py-4">
                            <li v-for="item in group.items" :key="item" class="flex gap-2.5 text-sm text-muted-foreground">
                                <span class="mt-1.5 size-1.5 shrink-0 rotate-45 bg-destructive/80" aria-hidden="true" />
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section class="bg-background cv-auto" aria-labelledby="faq-heading">
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
                    class="reveal relative overflow-hidden rounded-2xl bg-brand-gradient px-6 py-14 text-center text-primary-foreground shadow-[0_24px_60px_-32px_hsl(245_78%_40%/0.7)] sm:px-12 dark:border dark:border-primary/30 dark:bg-card dark:bg-none dark:text-foreground dark:shadow-none"
                >
                    <span class="absolute inset-x-12 top-0 h-px bg-highlight/80" aria-hidden="true" />
                    <div
                        class="pointer-events-none absolute inset-0 bg-grid [mask-image:radial-gradient(ellipse_at_center,black,transparent_75%)] opacity-60 dark:opacity-100"
                    />
                    <!-- Light bleeding in from the corners: a violet glow from the bottom right and a soft sheen from the top left. Amber is kept for the button so it stays the single high-contrast accent. -->
                    <div
                        class="pointer-events-none absolute inset-0 bg-[radial-gradient(75%_130%_at_100%_100%,hsl(285_85%_62%/0.5),transparent_62%),radial-gradient(60%_110%_at_0%_0%,hsl(0_0%_100%/0.16),transparent_60%)] dark:bg-[radial-gradient(75%_130%_at_100%_100%,hsl(270_80%_60%/0.22),transparent_62%),radial-gradient(60%_110%_at_0%_0%,hsl(var(--primary)/0.22),transparent_60%)]"
                        aria-hidden="true"
                    />
                    <div class="relative">
                        <h2 class="text-3xl font-semibold tracking-tight">Start from a stack that already sells and moderates</h2>
                        <p class="mx-auto mt-4 max-w-xl text-primary-foreground/80 dark:text-muted-foreground">
                            MetaForge is free and open source. Clone it, turn on the modules you need, and ship.
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
