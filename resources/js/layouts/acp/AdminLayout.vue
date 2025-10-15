<script setup lang="ts">
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, User, Shield, BookOpen, MessageSquare, LifeBuoy, Settings, Key, ShieldAlert, Award, CreditCard, Layers, ShieldCheck } from 'lucide-vue-next';

import { useRoles } from '@/composables/useRoles';
import { usePermissions } from '@/composables/usePermissions';

const { hasRole } = useRoles();
const { hasPermission } = usePermissions();

const isAdmin = computed(() => hasRole('admin|moderator|editor'));
const manageUsers = computed(() => hasPermission('users.acp.view'));
const manageACL = computed(() => hasPermission('acl.acp.view'));
const manageBlogs = computed(() => hasPermission('blogs.acp.view'));
const manageForums = computed(() => hasPermission('forums.acp.view'));
const manageSupport = computed(() => hasPermission('support.acp.view'));
const manageTokens = computed(() => hasPermission('tokens.acp.view'));
const manageBilling = computed(() => hasPermission('billing.acp.view'));
const manageSystem = computed(() => hasPermission('system.acp.view'));
const manageReputation = computed(() => hasPermission('reputation.acp.view'));
const manageTrustSafety = computed(() => hasPermission('trust_safety.acp.view'));

const sidebarNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/acp/dashboard',
        target: '_self',
        icon: LayoutGrid,
    },
    {
        title: 'Users',
        href: '/acp/users',
        target: '_self',
        icon: User,
    },
    {
        title: 'Access Control',
        href: '/acp/acl',
        target: '_self',
        icon: Shield,
    },
    {
        title: 'Blogs',
        href: '/acp/blogs',
        target: '_self',
        icon: BookOpen,
    },
    {
        title: 'Forums',
        href: '/acp/forums',
        target: '_self',
        icon: MessageSquare,
    },
    {
        title: 'Forum Reports',
        href: '/acp/forums/reports',
        target: '_self',
        icon: ShieldAlert,
    },
    {
        title: 'Badges',
        href: '/acp/reputation/badges',
        target: '_self',
        icon: Award,
    },
    {
        title: 'Support',
        href: '/acp/support',
        target: '_self',
        icon: LifeBuoy,
    },
    {
        title: 'Trust & Safety',
        href: '/acp/trust-safety',
        target: '_self',
        icon: ShieldCheck,
    },
    {
        title: 'Billing Invoices',
        href: '/acp/billing/invoices',
        target: '_self',
        icon: CreditCard,
    },
    {
        title: 'Subscription Plans',
        href: '/acp/billing/plans',
        target: '_self',
        icon: Layers,
    },
    {
        title: 'Access Tokens',
        href: '/acp/tokens',
        target: '_self',
        icon: Key,
    },
    {
        title: 'System Settings',
        href: '/acp/system',
        target: '_self',
        icon: Settings,
    },
];

const page = usePage();
const currentPath = page.props.ziggy?.location ? new URL(page.props.ziggy.location).pathname : '';

// Create a computed property to filter nav items based on the user's permissions/roles
const filteredNavItems = computed(() => {
    return sidebarNavItems.filter(item => {
        switch(item.title) {
            case 'Dashboard':
                return isAdmin.value;
            case 'Users':
                return manageUsers.value;
            case 'Access Control':
                return manageACL.value;
            case 'Blogs':
                return manageBlogs.value;
            case 'Forums':
                return manageForums.value;
            case 'Forum Reports':
                return manageForums.value;
            case 'Badges':
                return manageReputation.value || isAdmin.value;
            case 'Support':
                return manageSupport.value;
            case 'Trust & Safety':
                return manageTrustSafety.value;
            case 'Billing Invoices':
                return manageBilling.value;
            case 'Subscription Plans':
                return manageBilling.value;
            case 'Access Tokens':
                return manageTokens.value;
            case 'System Settings':
                return manageSystem.value;
            default:
                return false;
        }
    });
});
</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Admin Control Panel" :icon="Shield" description="Manage the system, content & users!" class="text-red-500" />

        <div class="flex flex-1 flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1">
                    <Button
                        v-for="item in filteredNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href" :target="item.target" class="flex items-center">
                            <!-- Render the icon if available -->
                            <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                            <span>{{ item.title }}</span>
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
