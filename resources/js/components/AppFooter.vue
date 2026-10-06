<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface FooterLink {
    title: string;
    href: string;
    external?: boolean;
}

const page = usePage<SharedData>();
const sections = computed(() => page.props.settings?.website_sections ?? { blog: true, forum: true, support: true, commerce: true });
const year = new Date().getFullYear();

const columns = computed<{ title: string; links: FooterLink[] }[]>(() => [
    {
        title: 'Product',
        links: [
            { title: 'Pricing', href: route('pricing') },
            ...(sections.value.commerce ? [{ title: 'Shop', href: route('shop.index') }] : []),
            { title: 'Dashboard', href: route('dashboard') },
        ],
    },
    {
        title: 'Community',
        links: [
            ...(sections.value.blog ? [{ title: 'Blog', href: route('blogs.index') }] : []),
            ...(sections.value.forum ? [{ title: 'Forum', href: route('forum.index') }] : []),
            ...(sections.value.support ? [{ title: 'Support', href: route('support') }] : []),
        ],
    },
    {
        title: 'Developers',
        links: [
            { title: 'API docs', href: route('api.docs'), external: true },
            { title: 'GitHub', href: 'https://github.com/MetaGrenade/laravel-vue', external: true },
        ],
    },
]);
</script>

<template>
    <footer class="mt-auto border-t bg-background">
        <div class="container-app grid gap-10 py-12 md:grid-cols-[1.5fr_repeat(3,1fr)]">
            <div class="space-y-3">
                <Link :href="route('home')" class="inline-flex rounded-md">
                    <AppLogo />
                </Link>
                <p class="max-w-xs text-sm text-muted-foreground">
                    A production-ready Laravel and Vue foundation for SaaS products and online communities.
                </p>
            </div>
            <nav v-for="column in columns.filter((c) => c.links.length)" :key="column.title" :aria-label="column.title">
                <h2 class="text-sm font-semibold">{{ column.title }}</h2>
                <ul class="mt-3 space-y-2">
                    <li v-for="link in column.links" :key="link.title">
                        <a
                            v-if="link.external"
                            :href="link.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {{ link.title }}
                        </a>
                        <Link v-else :href="link.href" class="text-sm text-muted-foreground transition-colors hover:text-foreground">
                            {{ link.title }}
                        </Link>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="border-t">
            <div class="container-app flex flex-col items-center justify-between gap-3 py-5 text-sm text-muted-foreground sm:flex-row">
                <p>&copy; {{ year }} {{ page.props.name }}. All rights reserved.</p>
                <ThemeToggle />
            </div>
        </div>
    </footer>
</template>
