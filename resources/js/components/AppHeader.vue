<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { useI18n } from '@/composables/useI18n';
import { useRoles } from '@/composables/useRoles';
import { currentEcho, loadEcho } from '@/lib/echo';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, CartSummary, NavItem, NotificationItem, SharedData, User } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Home,
    Layers,
    BookOpen,
    FolderGit2,
    LayoutGrid,
    Menu,
    Search,
    MessagesSquare,
    Shield,
    LifeBuoy,
    Bell,
    Check,
    Trash2,
    ShoppingBag,
    ShoppingCart,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

interface BroadcastNotificationPayload {
    id: string;
    type: string;
    data?: Record<string, unknown>;
    created_at?: string;
    read_at?: string | null;
}

interface Props {
    breadcrumbs?: BreadcrumbItem[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<SharedData>();
const user = computed<User | undefined>(() => page.props.auth?.user ?? undefined);

const notificationsState = reactive({
    items: [] as NotificationItem[],
    unreadCount: 0,
    hasMore: false,
});

const cart = computed<CartSummary | null>(() => page.props.cart ?? null);
const currencyCode = computed(() => cart.value?.currency ?? 'USD');
const cartItems = computed(() => cart.value?.items ?? []);
const cartItemCount = computed(() => cartItems.value.reduce((total, item) => total + item.quantity, 0));

const cartSubtotal = computed(() => cartItems.value.reduce((total, item) => total + Number(item.total), 0));

const formatCurrency = (value: number) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode.value,
        maximumFractionDigits: 2,
    }).format(value);

const notifications = computed<NotificationItem[]>(() => notificationsState.items);
const unreadNotificationCount = computed(() => notificationsState.unreadCount);
const notificationsHasMore = computed(() => notificationsState.hasMore);

const notificationProcessingIds = ref<Set<string>>(new Set());
const markAllProcessing = ref(false);

const currentPath = computed(() => page.url.split(/[?#]/)[0]);

/** A section link is active on its own page and on any page nested below it. */
const isActive = (href: string) => {
    if (href === '/') {
        return currentPath.value === '/';
    }

    return currentPath.value === href || currentPath.value.startsWith(`${href}/`);
};

const canRegister = computed(() => route().has('register'));

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

const synchroniseNotifications = () => {
    const bag = page.props.notifications;

    notificationsState.items = (bag?.items ?? []).slice();
    notificationsState.unreadCount = bag?.unread_count ?? 0;
    notificationsState.hasMore = bag?.has_more ?? false;
};

const handleIncomingNotification = (notification: BroadcastNotificationPayload) => {
    if (!notification?.id) {
        return;
    }

    const data = (notification.data ?? {}) as Record<string, unknown>;
    const createdAt = notification.created_at ?? new Date().toISOString();
    const titleCandidate = typeof data.title === 'string' ? data.title : undefined;
    const fallbackTitle = typeof data.thread_title === 'string' ? String(data.thread_title) : 'Notification';

    const item: NotificationItem = {
        id: notification.id,
        type: notification.type,
        title: titleCandidate ?? fallbackTitle,
        excerpt: typeof data.excerpt === 'string' ? data.excerpt : null,
        url: typeof data.url === 'string' ? data.url : null,
        data,
        created_at: createdAt,
        created_at_for_humans: 'Just now',
        read_at: notification.read_at ?? null,
    };

    const existing = notificationsState.items.filter((candidate) => candidate.id !== item.id);
    notificationsState.items = [item, ...existing].slice(0, 10);
    notificationsState.unreadCount = notificationsState.unreadCount + 1;
    notificationsState.hasMore = notificationsState.unreadCount > notificationsState.items.length;
};

let notificationChannelName: string | null = null;

const leaveNotificationChannel = () => {
    if (!notificationChannelName) {
        return;
    }

    currentEcho()?.leave(notificationChannelName);

    notificationChannelName = null;
};

const subscribeToNotificationChannel = async () => {
    const currentUser = user.value;

    if (!currentUser) {
        leaveNotificationChannel();
        return;
    }

    const echo = await loadEcho();

    // The user may have changed (e.g. logged out) while Echo was loading.
    if (!echo || user.value?.id !== currentUser.id) {
        leaveNotificationChannel();
        return;
    }

    // Echo adds the `private-` prefix itself; this must match routes/channels.php.
    const channelName = `App.Models.User.${currentUser.id}`;

    if (notificationChannelName === channelName) {
        return;
    }

    leaveNotificationChannel();

    notificationChannelName = channelName;

    echo.private(channelName).notification((notification: BroadcastNotificationPayload) => {
        handleIncomingNotification(notification);
    });
};

watch(
    () => page.props.notifications,
    () => {
        synchroniseNotifications();
    },
    { immediate: true, deep: true },
);

watch(
    () => user.value?.id,
    () => {
        void subscribeToNotificationChannel();
    },
);

onMounted(() => {
    window.addEventListener('keydown', handleSearchShortcut);
    void subscribeToNotificationChannel();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleSearchShortcut);
    leaveNotificationChannel();
});

type SectionAwareNavItem = NavItem & {
    section?: 'blog' | 'forum' | 'support' | 'commerce';
    /** Only show to signed-in users, or to users with one of these roles (pipe-separated). */
    requiresAuth?: boolean;
    roles?: string;
};

const { hasRole } = useRoles();
const { t } = useI18n();

const isNavItemVisible = (item: SectionAwareNavItem): boolean => {
    if (item.section && !websiteSections.value[item.section]) {
        return false;
    }

    if ((item.requiresAuth || item.roles) && !user.value) {
        return false;
    }

    return item.roles ? hasRole(item.roles) : true;
};

const websiteSections = computed(() => {
    const defaults = { blog: true, forum: true, support: true, commerce: true } as const;
    const settings = page.props.settings?.website_sections ?? defaults;

    return {
        blog: settings.blog ?? defaults.blog,
        forum: settings.forum ?? defaults.forum,
        support: settings.support ?? defaults.support,
        commerce: settings.commerce ?? defaults.commerce,
    };
});

const commerceEnabled = computed(() => Boolean(websiteSections.value.commerce));

const baseMainNavItems: SectionAwareNavItem[] = [
    { title: 'ui.nav.home', href: '/', target: '_self', icon: Home },
    { title: 'ui.nav.pricing', href: '/pricing', target: '_self', icon: Layers },
    { title: 'ui.nav.shop', href: '/shop', target: '_self', icon: ShoppingBag, section: 'commerce' },
    { title: 'ui.nav.dashboard', href: '/dashboard', target: '_self', icon: LayoutGrid, requiresAuth: true },
    { title: 'ui.nav.blog', href: '/blogs', target: '_self', icon: BookOpen, section: 'blog' },
    { title: 'ui.nav.forum', href: '/forum', target: '_self', icon: MessagesSquare, section: 'forum' },
];

const baseRightNavItems: SectionAwareNavItem[] = [
    {
        title: 'ui.nav.admin',
        href: '/acp',
        target: '_self',
        icon: Shield,
        roles: 'admin|editor|moderator',
    },
    {
        title: 'ui.nav.support',
        href: '/support',
        target: '_self',
        icon: LifeBuoy,
        section: 'support',
    },
    {
        title: 'ui.nav.repository',
        href: 'https://github.com/MetaGrenade/laravel-vue',
        target: '_blank',
        icon: FolderGit2,
    },
];

const isExternal = (item: NavItem) => item.target === '_blank';

// Item titles above are translation keys; resolve them for display.
const translateItems = (items: SectionAwareNavItem[]): NavItem[] => items.filter(isNavItemVisible).map((item) => ({ ...item, title: t(item.title) }));

const mainNavItems = computed<NavItem[]>(() => translateItems(baseMainNavItems));

const rightNavItems = computed<NavItem[]>(() => translateItems(baseRightNavItems));

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
    <CommandPalette v-model:open="isCommandPaletteOpen" />

    <header class="sticky top-0 z-40 w-full border-b bg-background">
        <div class="container-app flex h-16 items-center gap-2">
            <!-- Mobile menu -->
            <Sheet>
                <SheetTrigger as-child>
                    <Button variant="ghost" size="icon" class="-ml-2 lg:hidden" :aria-label="t('ui.nav.open_menu')">
                        <Menu class="size-5" />
                    </Button>
                </SheetTrigger>
                <SheetContent side="left" class="flex w-[300px] flex-col gap-0 p-0">
                    <SheetHeader class="border-b px-5 py-4 text-left">
                        <SheetTitle class="sr-only">Navigation menu</SheetTitle>
                        <AppLogo />
                    </SheetHeader>
                    <nav class="flex-1 space-y-1 overflow-y-auto p-3" :aria-label="t('ui.nav.main')">
                        <Link
                            v-for="item in mainNavItems"
                            :key="item.title"
                            :href="item.href"
                            :class="
                                cn(
                                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                                    isActive(item.href) && 'bg-accent text-foreground',
                                )
                            "
                        >
                            <component v-if="item.icon" :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </Link>
                        <Separator class="my-3" />
                        <component
                            :is="isExternal(item) ? 'a' : Link"
                            v-for="item in rightNavItems"
                            :key="item.title"
                            :href="item.href"
                            :target="isExternal(item) ? '_blank' : undefined"
                            :rel="isExternal(item) ? 'noopener noreferrer' : undefined"
                            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            <component v-if="item.icon" :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </component>
                    </nav>
                    <div class="flex items-center justify-between border-t px-5 py-3 text-sm text-muted-foreground">
                        Theme
                        <ThemeToggle />
                    </div>
                    <div v-if="!user" class="grid gap-2 border-t p-4">
                        <Button variant="outline" as-child>
                            <Link :href="route('login')">{{ t('ui.actions.log_in') }}</Link>
                        </Button>
                        <Button v-if="canRegister" as-child>
                            <Link :href="route('register')">{{ t('ui.actions.get_started') }}</Link>
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>

            <!-- Logo -->
            <Link :href="route('home')" class="mr-4 flex shrink-0 items-center rounded-md" :aria-label="t('ui.nav.home')">
                <AppLogo />
            </Link>

            <!-- Desktop navigation -->
            <nav class="hidden items-center gap-1 lg:flex" :aria-label="t('ui.nav.main')">
                <Link
                    v-for="item in mainNavItems"
                    :key="item.title"
                    :href="item.href"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    :class="
                        cn(
                            'relative rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                            isActive(item.href) &&
                                'bg-accent text-foreground after:absolute after:inset-x-3 after:-bottom-4 after:h-0.5 after:rounded-full after:bg-highlight',
                        )
                    "
                >
                    {{ item.title }}
                </Link>
            </nav>

            <!-- Right side -->
            <div class="ml-auto flex items-center gap-1">
                <button
                    type="button"
                    class="hidden h-9 w-56 items-center gap-2 rounded-md border bg-muted/40 px-3 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground md:flex xl:w-64"
                    @click="openCommandPalette"
                >
                    <Search class="size-4" />
                    <span>{{ t('ui.actions.search_placeholder') }}</span>
                    <kbd class="ml-auto rounded border bg-background px-1.5 font-mono text-[0.7rem] font-medium">Ctrl K</kbd>
                </button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="text-muted-foreground hover:text-foreground md:hidden"
                    :aria-label="t('ui.actions.search')"
                    @click="openCommandPalette"
                >
                    <Search class="size-[1.15rem]" />
                </Button>

                <TooltipProvider :delay-duration="300">
                    <div class="hidden items-center lg:flex">
                        <Tooltip v-for="item in rightNavItems" :key="item.title">
                            <TooltipTrigger as-child>
                                <Button variant="ghost" size="icon" class="text-muted-foreground hover:text-foreground" as-child>
                                    <component
                                        :is="isExternal(item) ? 'a' : Link"
                                        :href="item.href"
                                        :target="isExternal(item) ? '_blank' : undefined"
                                        :rel="isExternal(item) ? 'noopener noreferrer' : undefined"
                                        :aria-label="item.title"
                                        :class="isActive(item.href) && 'bg-accent text-foreground'"
                                    >
                                        <component :is="item.icon" class="size-[1.15rem]" />
                                    </component>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>{{ item.title }}</TooltipContent>
                        </Tooltip>
                    </div>
                </TooltipProvider>

                <!-- On phones the toggle lives in the menu sheet and the footer to keep the bar uncluttered. -->
                <div class="hidden sm:block">
                    <ThemeToggle />
                </div>

                <Sheet v-if="commerceEnabled">
                    <SheetTrigger as-child>
                        <Button variant="ghost" size="icon" class="relative text-muted-foreground hover:text-foreground" aria-label="Open cart">
                            <ShoppingCart class="size-[1.15rem]" />
                            <span
                                v-if="cartItemCount > 0"
                                class="absolute top-0.5 right-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-highlight px-1 text-[0.65rem] font-semibold text-highlight-foreground"
                            >
                                {{ cartItemCount > 9 ? '9+' : cartItemCount }}
                            </span>
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="flex w-full max-w-md flex-col gap-0 p-0">
                        <SheetHeader class="space-y-1 border-b px-6 py-4 text-left">
                            <SheetTitle>Your cart</SheetTitle>
                            <p class="text-sm text-muted-foreground">Review your items before checkout.</p>
                        </SheetHeader>
                        <div class="flex-1 space-y-3 overflow-y-auto px-6 py-4">
                            <div v-if="!cartItems.length" class="flex flex-col items-center gap-2 py-12 text-center">
                                <ShoppingCart class="size-8 text-muted-foreground/60" />
                                <p class="text-sm text-muted-foreground">Your cart is empty.</p>
                            </div>
                            <div v-for="item in cartItems" :key="item.id" class="flex items-start justify-between gap-4 rounded-lg border p-3">
                                <div class="min-w-0 space-y-1">
                                    <p class="truncate text-sm font-medium">{{ item.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ item.variant || 'Base product' }} · Qty {{ item.quantity }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold tabular-nums">{{ formatCurrency(Number(item.total)) }}</p>
                                    <p class="text-xs text-muted-foreground tabular-nums">{{ formatCurrency(Number(item.unit_price)) }} each</p>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-3 border-t px-6 py-4">
                            <dl class="text-sm">
                                <div class="flex justify-between text-base font-semibold">
                                    <dt>Subtotal</dt>
                                    <dd class="tabular-nums">{{ formatCurrency(cartSubtotal) }}</dd>
                                </div>
                            </dl>
                            <div class="flex gap-2">
                                <Button v-if="cartItems.length" class="flex-1" as-child>
                                    <Link :href="route('shop.cart')">View cart</Link>
                                </Button>
                                <Button v-else class="flex-1" disabled>View cart</Button>
                                <Button variant="outline" class="flex-1" as-child>
                                    <Link :href="route('shop.index')">Keep shopping</Link>
                                </Button>
                            </div>
                        </div>
                    </SheetContent>
                </Sheet>

                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="relative text-muted-foreground hover:text-foreground"
                            :aria-label="unreadNotificationCount > 0 ? `${unreadNotificationCount} unread notifications` : 'Notifications'"
                        >
                            <Bell class="size-[1.15rem]" />
                            <span
                                v-if="unreadNotificationCount > 0"
                                class="absolute top-0.5 right-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-highlight px-1 text-[0.65rem] font-semibold text-highlight-foreground"
                            >
                                {{ unreadNotificationCount > 9 ? '9+' : unreadNotificationCount }}
                            </span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-[22rem] p-0">
                        <div class="flex items-center justify-between px-4 py-3">
                            <p class="text-sm font-semibold">Notifications</p>
                            <Button
                                v-if="unreadNotificationCount > 0"
                                variant="ghost"
                                size="xs"
                                class="text-muted-foreground"
                                :disabled="markAllProcessing"
                                @click.prevent="markAllNotificationsAsRead"
                            >
                                <Check />
                                Mark all read
                            </Button>
                        </div>
                        <DropdownMenuSeparator class="m-0" />
                        <div v-if="notifications.length === 0" class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                            <Bell class="size-6 text-muted-foreground/60" />
                            <p class="text-sm text-muted-foreground">You're all caught up.</p>
                        </div>
                        <ul v-else class="max-h-96 divide-y overflow-y-auto">
                            <li v-for="notification in notifications" :key="notification.id" class="group flex gap-3 px-4 py-3 hover:bg-muted/50">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-highlight" aria-hidden="true" />
                                <div class="min-w-0 flex-1">
                                    <button
                                        type="button"
                                        class="text-left text-sm font-medium hover:underline disabled:opacity-60"
                                        :disabled="isNotificationProcessing(notification.id)"
                                        @click.prevent="viewNotification(notification)"
                                    >
                                        {{ notification.title }}
                                    </button>
                                    <p v-if="notification.excerpt" class="mt-0.5 line-clamp-2 text-sm text-muted-foreground">
                                        {{ notification.excerpt }}
                                    </p>
                                    <div class="mt-1.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        <span v-if="notification.created_at_for_humans">{{ notification.created_at_for_humans }}</span>
                                        <button
                                            type="button"
                                            class="hover:text-foreground disabled:opacity-60"
                                            :disabled="isNotificationProcessing(notification.id)"
                                            @click.prevent="markNotificationAsRead(notification.id)"
                                        >
                                            Mark read
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 hover:text-destructive disabled:opacity-60"
                                            :disabled="isNotificationProcessing(notification.id)"
                                            @click.prevent="deleteNotification(notification.id)"
                                        >
                                            <Trash2 class="size-3" />
                                            Dismiss
                                        </button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                        <template v-if="notificationsHasMore">
                            <DropdownMenuSeparator class="m-0" />
                            <p class="px-4 py-2 text-xs text-muted-foreground">
                                Showing the latest {{ notifications.length }} of {{ unreadNotificationCount }} unread notifications.
                            </p>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>

                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon" class="ml-1 rounded-full" aria-label="Account menu">
                            <Avatar class="size-8">
                                <AvatarImage v-if="user?.avatar_url" :src="user.avatar_url" :alt="user?.nickname ?? ''" />
                                <AvatarFallback class="bg-primary/10 text-xs font-semibold text-primary">
                                    {{ getInitials(user?.nickname ?? '') }}
                                </AvatarFallback>
                            </Avatar>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>

                <template v-else>
                    <Button variant="ghost" size="sm" class="ml-1 hidden sm:inline-flex" as-child>
                        <Link :href="route('login')">{{ t('ui.actions.log_in') }}</Link>
                    </Button>
                    <Button v-if="canRegister" size="sm" class="hidden sm:inline-flex" as-child>
                        <Link :href="route('register')">{{ t('ui.actions.get_started') }}</Link>
                    </Button>
                </template>
            </div>
        </div>
    </header>

    <div v-if="props.breadcrumbs.length > 1" class="border-b bg-background">
        <div class="container-app flex h-11 items-center">
            <Breadcrumbs :breadcrumbs="breadcrumbs" />
        </div>
    </div>
</template>
