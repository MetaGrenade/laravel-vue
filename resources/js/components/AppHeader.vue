<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuLink,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import type { BreadcrumbItem, NavItem, NotificationItem, SharedData, User } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { BookOpen, Folder, LayoutGrid, Menu, Search, Megaphone, Shield, LifeBuoy, Bell, Check, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItem[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();
const user = computed<User | undefined>(() => page.props.auth?.user ?? undefined);

const notifications = computed<NotificationItem[]>(() => page.props.notifications?.items ?? []);
const unreadNotificationCount = computed(() => page.props.notifications?.unread_count ?? 0);
const notificationsHasMore = computed(() => page.props.notifications?.has_more ?? false);

const notificationProcessingIds = ref<Set<string>>(new Set());
const markAllProcessing = ref(false);

const isCurrentRoute = computed(() => (url: string) => page.url === url);

const activeItemStyles = computed(
    () => (url: string) => (isCurrentRoute.value(url) ? 'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100' : ''),
);

const isCommandPaletteOpen = ref(false);

const openCommandPalette = () => {
    isCommandPaletteOpen.value = true;
};

const closeCommandPalette = () => {
    isCommandPaletteOpen.value = false;
};

const handleSearchShortcut = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        openCommandPalette();
    }

    if (event.key === 'Escape' && isCommandPaletteOpen.value) {
        closeCommandPalette();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleSearchShortcut);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleSearchShortcut);
});

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard',   target: '_self', icon: LayoutGrid },
    { title: 'Blog',      href: '/blogs',       target: '_self', icon: BookOpen },
    { title: 'Forum',     href: '/forum',       target: '_self', icon: Megaphone },
];

const rightNavItems: NavItem[] = [
    {
        title: 'Admin',
        href: '/acp',
        target: '_self',
        icon: Shield,
        color: 'rgb(197,102,34)' // orange
    },
    {
        title: 'Support',
        href: '/support',
        target: '_self',
        icon: LifeBuoy,
        color: 'rgb(197,34,34)' // red,
    },
    {
        title: 'Repository',
        href: 'https://github.com/MetaGrenade/laravel-vue',
        target: '_blank',
        icon: Folder,
        color: 'rgb(34, 197, 94)' // green,
    },
];

const setNotificationProcessing = (id: string, processing: boolean) => {
    if (!id) {
        return;
    }

    const next = new Set(notificationProcessingIds.value);

    if (processing) {
        next.add(id);
    } else {
        next.delete(id);
    }

    notificationProcessingIds.value = next;
};

const isNotificationProcessing = (id: string) => notificationProcessingIds.value.has(id);

const markNotificationAsRead = (id: string) => {
    if (!id || isNotificationProcessing(id)) {
        return;
    }

    setNotificationProcessing(id, true);

    router.post(
        route('notifications.read', { notification: id }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['notifications'],
            onFinish: () => {
                setNotificationProcessing(id, false);
            },
        },
    );
};

const deleteNotification = (id: string) => {
    if (!id || isNotificationProcessing(id)) {
        return;
    }

    setNotificationProcessing(id, true);

    router.delete(route('notifications.destroy', { notification: id }), {
        preserveScroll: true,
        preserveState: true,
        only: ['notifications'],
        onFinish: () => {
            setNotificationProcessing(id, false);
        },
    });
};

const markAllNotificationsAsRead = () => {
    if (unreadNotificationCount.value === 0 || markAllProcessing.value) {
        return;
    }

    markAllProcessing.value = true;

    router.post(
        route('notifications.read-all'),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['notifications'],
            onFinish: () => {
                markAllProcessing.value = false;
            },
        },
    );
};

const viewNotification = (notification: NotificationItem) => {
    if (!notification.url) {
        markNotificationAsRead(notification.id);
        return;
    }

    if (isNotificationProcessing(notification.id)) {
        return;
    }

    setNotificationProcessing(notification.id, true);

    router.post(
        route('notifications.read', { notification: notification.id }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['notifications'],
            onSuccess: () => {
                router.visit(notification.url as string);
            },
            onFinish: () => {
                setNotificationProcessing(notification.id, false);
            },
        },
    );
};
</script>

<template>
    <div>
        <CommandPalette v-model:open="isCommandPaletteOpen" />

        <!-- Fixed header -->
        <div class="fixed inset-x-0 top-0 z-50 border-b border-sidebar-border/80 bg-white dark:bg-neutral-900">
            <div class="mx-auto flex h-16 items-center px-4 md:max-w-7xl">
                <!-- Mobile Menu -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger :as-child="true">
                            <Button variant="ghost" size="icon" class="mr-2 h-9 w-9">
                                <Menu class="h-5 w-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent side="left" class="w-[300px] p-6">
                            <SheetTitle class="sr-only">Navigation Menu</SheetTitle>
                            <SheetHeader class="flex justify-start text-left">
                                <AppLogoIcon class="size-6 fill-current text-black dark:text-white" />
                            </SheetHeader>
                            <div class="flex h-full flex-1 flex-col justify-between space-y-4 py-6">
                                <nav class="-mx-3 space-y-1">
                                    <Link
                                        v-for="item in mainNavItems"
                                        :key="item.title"
                                        :href="item.href"
                                        :target="item.target"
                                        class="flex items-center gap-x-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent"
                                        :class="activeItemStyles(item.href)"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                        {{ item.title }}
                                    </Link>
                                </nav>
                                <div class="flex flex-col space-y-4">
                                    <a
                                        v-for="item in rightNavItems"
                                        :key="item.title"
                                        :href="item.href"
                                        :target="item.target"
                                        rel="noopener noreferrer"
                                        class="flex items-center space-x-2 text-sm font-medium"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-5 w-5" :style="{ color: item.color }" />
                                        <span>{{ item.title }}</span>
                                    </a>
                                </div>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <!-- Logo -->
                <Link :href="route('dashboard')" class="flex items-center gap-x-2">
                    <AppLogo />
                </Link>

                <!-- Desktop Menu -->
                <div class="hidden h-full lg:flex lg:flex-1">
                    <NavigationMenu class="ml-10 flex h-full items-stretch">
                        <NavigationMenuList class="flex h-full items-stretch space-x-2">
                            <NavigationMenuItem
                                v-for="(item, index) in mainNavItems"
                                :key="index"
                                class="relative flex h-full items-center"
                            >
                                <Link :href="item.href" :target="item.target">
                                    <NavigationMenuLink
                                        :class="[navigationMenuTriggerStyle(), activeItemStyles(item.href), 'h-9 cursor-pointer px-3']"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                                        {{ item.title }}
                                    </NavigationMenuLink>
                                </Link>
                                <div
                                    v-if="isCurrentRoute(item.href)"
                                    class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"
                                />
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenu>
                </div>

                <!-- Right side -->
                <div class="ml-auto flex items-center space-x-2">
                    <div class="relative flex items-center space-x-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="group h-9 w-9 cursor-pointer"
                            :title="'Search (Ctrl+K)'"
                            @click="openCommandPalette"
                        >
                            <span class="sr-only">Open search (Ctrl+K)</span>
                            <Search class="size-5 opacity-80 group-hover:opacity-100" />
                        </Button>

                        <div class="hidden space-x-1 lg:flex">
                            <template v-for="item in rightNavItems" :key="item.title">
                                <TooltipProvider :delay-duration="0">
                                    <Tooltip>
                                        <TooltipTrigger>
                                            <Button variant="ghost" size="icon" as-child class="group h-9 w-9 cursor-pointer">
                                                <a :href="item.href" :target="item.target" rel="noopener noreferrer">
                                                    <span class="sr-only">{{ item.title }}</span>
                                                    <component
                                                        :is="item.icon"
                                                        class="size-5 opacity-80 group-hover:opacity-100"
                                                        :style="{ color: item.color }"
                                                    />
                                                </a>
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p>{{ item.title }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </template>
                        </div>
                    </div>

                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="group relative h-9 w-9 cursor-pointer"
                                :aria-label="unreadNotificationCount > 0 ? `${unreadNotificationCount} unread notifications` : 'Notifications'"
                            >
                                <Bell class="size-5 opacity-80 group-hover:opacity-100" />
                                <span
                                    v-if="unreadNotificationCount > 0"
                                    class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-xs font-semibold text-primary-foreground"
                                >
                                    {{ unreadNotificationCount > 9 ? '9+' : unreadNotificationCount }}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-80 p-0">
                            <div class="flex items-center justify-between px-4 py-2">
                                <p class="text-sm font-semibold text-foreground">Notifications</p>
                                <Button
                                    v-if="unreadNotificationCount > 0"
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs"
                                    :disabled="markAllProcessing"
                                    @click.prevent="markAllNotificationsAsRead"
                                >
                                    <Check class="mr-1 h-3.5 w-3.5" />
                                    Mark all as read
                                </Button>
                            </div>
                            <DropdownMenuSeparator />
                            <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-muted-foreground">
                                You're all caught up!
                            </div>
                            <div v-else class="max-h-80 overflow-y-auto">
                                <div
                                    v-for="notification in notifications"
                                    :key="notification.id"
                                    class="flex items-start gap-3 border-b border-border/40 px-4 py-3 last:border-b-0"
                                >
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-foreground">
                                            {{ notification.title }}
                                        </p>
                                        <p v-if="notification.excerpt" class="mt-1 text-sm text-muted-foreground">
                                            {{ notification.excerpt }}
                                        </p>
                                        <p
                                            v-if="notification.created_at_for_humans"
                                            class="mt-1 text-xs uppercase tracking-wide text-muted-foreground"
                                        >
                                            {{ notification.created_at_for_humans }}
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-end gap-1">
                                        <Button
                                            v-if="notification.url"
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                            :disabled="isNotificationProcessing(notification.id)"
                                            @click.prevent="viewNotification(notification)"
                                        >
                                            View
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs"
                                            :disabled="isNotificationProcessing(notification.id)"
                                            @click.prevent="markNotificationAsRead(notification.id)"
                                        >
                                            <Check class="mr-1 h-3 w-3" />
                                            Mark read
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 px-2 text-xs text-destructive hover:text-destructive"
                                            :disabled="isNotificationProcessing(notification.id)"
                                            @click.prevent="deleteNotification(notification.id)"
                                        >
                                            <Trash2 class="mr-1 h-3 w-3" />
                                            Dismiss
                                        </Button>
                                    </div>
                                </div>
                            </div>
                            <DropdownMenuSeparator v-if="notificationsHasMore" />
                            <div v-if="notificationsHasMore" class="px-4 py-2 text-xs text-muted-foreground">
                                Showing latest {{ notifications.length }} of {{ unreadNotificationCount }} unread notifications.
                            </div>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-10 w-auto rounded-full p-1 focus-within:ring-2 focus-within:ring-primary"
                            >
                                <Avatar class="size-8 overflow-hidden rounded-full">
                                    <AvatarImage
                                        v-if="user?.avatar_url"
                                        :src="user.avatar_url"
                                        :alt="user?.nickname ?? ''"
                                    />
                                    <AvatarFallback class="rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white">
                                        {{ getInitials(user?.nickname ?? '') }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <!-- Breadcrumbs, pushed below fixed header -->
        <div
            v-if="props.breadcrumbs.length > 1"
            class="flex w-full border-b border-sidebar-border/70"
            style="margin-top: 0.5rem;"
        >
            <div class="mx-auto flex h-12 w-full items-center justify-start px-4 text-neutral-500 md:max-w-7xl">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
