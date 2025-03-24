<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/acp/dashboard',
    },
    {
        title: 'User Management',
        href: '/acp/users',
    },
    {
        title: 'Blog Management',
        href: '/acp/blogs',
    },
    {
        title: 'Forum Management',
        href: '/acp/forums',
    },
    {
        title: 'Permission Management',
        href: '/acp/permissions',
    },
];

const page = usePage();

const currentPath = page.props.ziggy?.location ? new URL(page.props.ziggy.location).pathname : '';
</script>

<template>
    <div class="min-h-screen px-4 py-6">
        <Heading title="Admin Control Panel" description="Manage the system, content & users!" class="text-red-500" />

        <div class="flex flex-1 flex-col min-h-screen space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 md:hidden" />

            <div class="flex flex-1 flex-col md:max-w-5xl">
                <!-- Ensure the section fills remaining space -->
                <section class="flex flex-1 h-full max-w-5xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>

