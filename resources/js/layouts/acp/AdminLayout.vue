<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { useRoles } from '@/composables/useRoles';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Award,
    BookOpen,
    CreditCard,
    Key,
    Layers,
    LayoutGrid,
    LifeBuoy,
    MessageCircle,
    MessageSquare,
    Package,
    Percent,
    ReceiptText,
    Search,
    Settings,
    Shield,
    ShieldAlert,
    ShieldCheck,
    ShoppingBag,
    Tags,
    Truck,
    User,
    Vote,
    Webhook,
} from '@lucide/vue';
import { computed, type Component } from 'vue';

interface AdminNavItem {
    title: string;
    href: string;
    icon: Component;
    visible: boolean;
}

interface AdminNavGroup {
    title: string;
    items: AdminNavItem[];
}

const { hasRole } = useRoles();
const { hasPermission } = usePermissions();

const page = usePage<SharedData>();

const sections = computed(() => {
    const settings = page.props.settings?.website_sections;

    return {
        blog: settings?.blog ?? true,
        forum: settings?.forum ?? true,
        support: settings?.support ?? true,
        commerce: settings?.commerce ?? true,
    };
});

const navGroups = computed<AdminNavGroup[]>(() => {
    const isStaff = hasRole('admin|moderator|editor');
    const can = (permission: string) => hasPermission(permission);

    const groups: AdminNavGroup[] = [
        {
            title: 'Overview',
            items: [
                { title: 'Dashboard', href: '/acp/dashboard', icon: LayoutGrid, visible: isStaff },
                { title: 'Search analytics', href: '/acp/search-analytics', icon: Search, visible: isStaff || can('search.acp.view') },
            ],
        },
        {
            title: 'People',
            items: [
                { title: 'Users', href: '/acp/users', icon: User, visible: can('users.acp.view') },
                { title: 'Access control', href: '/acp/acl', icon: Shield, visible: can('acl.acp.view') },
                { title: 'Badges', href: '/acp/reputation/badges', icon: Award, visible: isStaff || can('reputation.acp.view') },
                { title: 'Trust & safety', href: '/acp/trust-safety', icon: ShieldCheck, visible: can('trust_safety.acp.view') },
            ],
        },
        {
            title: 'Content',
            items: [
                { title: 'Blogs', href: '/acp/blogs', icon: BookOpen, visible: can('blogs.acp.view') && sections.value.blog },
                { title: 'Blog comments', href: '/acp/blog-comments', icon: MessageCircle, visible: can('blogs.acp.view') && sections.value.blog },
                { title: 'Forums', href: '/acp/forums', icon: MessageSquare, visible: can('forums.acp.view') && sections.value.forum },
                { title: 'Forum reports', href: '/acp/forums/reports', icon: ShieldAlert, visible: can('forums.acp.view') && sections.value.forum },
                { title: 'Polls', href: '/acp/polls', icon: Vote, visible: can('polls.acp.view') },
            ],
        },
        {
            title: 'Support',
            items: [{ title: 'Support desk', href: '/acp/support', icon: LifeBuoy, visible: can('support.acp.view') && sections.value.support }],
        },
        {
            title: 'Commerce & billing',
            items: [
                { title: 'Commerce', href: '/acp/commerce', icon: ShoppingBag, visible: can('commerce.acp.view') && sections.value.commerce },
                { title: 'Products', href: '/acp/commerce/products', icon: Package, visible: can('commerce.acp.view') && sections.value.commerce },
                { title: 'Orders', href: '/acp/commerce/orders', icon: ReceiptText, visible: can('commerce.acp.view') && sections.value.commerce },
                {
                    title: 'Brands and tags',
                    href: '/acp/commerce/taxonomy',
                    icon: Tags,
                    visible: can('commerce.acp.view') && sections.value.commerce,
                },
                { title: 'Shipping', href: '/acp/commerce/shipping', icon: Truck, visible: can('commerce.acp.view') && sections.value.commerce },
                { title: 'Tax rates', href: '/acp/commerce/tax-rates', icon: Percent, visible: can('commerce.acp.view') && sections.value.commerce },
                { title: 'Subscription plans', href: '/acp/billing/plans', icon: Layers, visible: can('billing.acp.view') },
                { title: 'Invoices', href: '/acp/billing/invoices', icon: CreditCard, visible: can('billing.acp.view') },
                { title: 'Webhooks', href: '/acp/billing/webhooks', icon: Webhook, visible: can('billing.acp.view') },
            ],
        },
        {
            title: 'System',
            items: [
                { title: 'Access tokens', href: '/acp/tokens', icon: Key, visible: can('tokens.acp.view') },
                { title: 'System settings', href: '/acp/system', icon: Settings, visible: can('system.acp.view') },
            ],
        },
    ];

    return groups.map((group) => ({ ...group, items: group.items.filter((item) => item.visible) })).filter((group) => group.items.length > 0);
});

const currentPath = computed(() => page.url.split(/[?#]/)[0]);

/** The most specific nav entry containing the current page (so /acp/forums/reports doesn't also light up Forums). */
const activeHref = computed(() => {
    const matches = navGroups.value
        .flatMap((group) => group.items)
        .filter((item) => currentPath.value === item.href || currentPath.value.startsWith(`${item.href}/`))
        .sort((a, b) => b.href.length - a.href.length);

    return matches[0]?.href ?? null;
});

const linkClass = (href: string) =>
    cn(
        'flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
        activeHref.value === href && 'bg-accent font-medium text-foreground',
    );
</script>

<template>
    <div class="px-4 py-6">
        <div class="mb-6 flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-lg border bg-card shadow-xs">
                <Shield class="size-5 text-primary" />
            </span>
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Admin control panel</h1>
                <p class="text-sm text-muted-foreground">Manage the platform, its content and its members.</p>
            </div>
        </div>

        <!-- Mobile / tablet: horizontally scrolling tabs -->
        <nav class="-mx-4 mb-6 scrollbar-thin overflow-x-auto border-b px-4 lg:hidden" aria-label="Admin sections">
            <ul class="flex w-max gap-1 pb-2">
                <template v-for="group in navGroups" :key="group.title">
                    <li v-for="item in group.items" :key="item.href">
                        <Link
                            :href="item.href"
                            :class="cn(linkClass(item.href), 'whitespace-nowrap')"
                            :aria-current="activeHref === item.href ? 'page' : undefined"
                        >
                            <component :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </Link>
                    </li>
                </template>
            </ul>
        </nav>

        <div class="grid gap-8 lg:grid-cols-[13.5rem_minmax(0,1fr)]">
            <aside class="hidden lg:block">
                <nav class="sticky top-20 max-h-[calc(100svh-6rem)] scrollbar-thin space-y-5 overflow-y-auto pr-1 pb-4" aria-label="Admin sections">
                    <div v-for="group in navGroups" :key="group.title">
                        <p class="mb-1.5 px-2.5 text-xs font-medium text-muted-foreground/80">{{ group.title }}</p>
                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.href">
                                <Link :href="item.href" :class="linkClass(item.href)" :aria-current="activeHref === item.href ? 'page' : undefined">
                                    <component :is="item.icon" :class="cn('size-4', activeHref === item.href && 'text-primary')" />
                                    {{ item.title }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </nav>
            </aside>

            <!-- min-w-0 + a minmax(0,1fr) column let wide tables scroll within their own container instead of stretching the page -->
            <section class="grid min-w-0 grid-cols-[minmax(0,1fr)] content-start">
                <slot />
            </section>
        </div>
    </div>
</template>
