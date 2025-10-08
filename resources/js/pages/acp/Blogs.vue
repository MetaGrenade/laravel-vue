<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import type { CheckboxRootProps } from 'radix-vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { useDebounceFn } from '@vueuse/core';
import { LineChart } from '@/components/ui/chart-line';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import {
    FileText,
    Edit3,
    CheckCircle,
    Ellipsis,
    Eye,
    EyeOff,
    Trash2,
    Pencil,
    Archive,
    ArchiveRestore,
    CalendarClock,
    TrendingUp,
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useConfirmDialog } from '@/composables/useConfirmDialog';
import { CurveType } from '@unovis/ts';

// dayjs composable for human readable dates
const { fromNow } = useUserTimezone();
const numberFormatter = new Intl.NumberFormat();
const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);

const parseViews = (value: string) => {
    const trimmed = value.trim();

    if (trimmed === '') {
        return undefined;
    }

    const parsed = Number.parseInt(trimmed, 10);

    if (Number.isNaN(parsed) || parsed < 0) {
        return undefined;
    }

    return parsed;
};

const buildQueryParams = (
    overrides: Partial<{
        page: number;
        search: string | null;
        status: string[] | null;
        sort: SortOption | null;
        min_views: number | null;
        max_views: number | null;
    }> = {},
) => {
    const params: Record<string, unknown> = {};

    const searchValue = overrides.search ?? searchQuery.value;
    if (searchValue && searchValue.trim() !== '') {
        params.search = searchValue.trim();
    }

    const statusValue = overrides.status ?? statusFilters.value;
    if (statusValue && statusValue.length > 0) {
        params.status = statusValue;
    }

    const sortValue = overrides.sort ?? sortOption.value;
    if (sortValue && sortValue !== defaultSortOption) {
        params.sort = sortValue;
    }

    const minViewsValue =
        overrides.min_views !== undefined ? overrides.min_views : parseViews(minViews.value);
    if (typeof minViewsValue === 'number') {
        params.min_views = minViewsValue;
    }

    const maxViewsValue =
        overrides.max_views !== undefined ? overrides.max_views : parseViews(maxViews.value);
    if (typeof maxViewsValue === 'number') {
        if (typeof params.min_views === 'number' && maxViewsValue < params.min_views) {
            // Skip conflicting max views filter
        } else {
            params.max_views = maxViewsValue;
        }
    }

    const pageValue = overrides.page;
    if (typeof pageValue === 'number' && pageValue > 1) {
        params.page = pageValue;
    }

    return params;
};

const navigateWithFilters = (
    overrides: Partial<{
        page: number;
        search: string | null;
        status: string[] | null;
        sort: SortOption | null;
        min_views: number | null;
        max_views: number | null;
    }> = {},
) => {
    router.get(
        route('acp.blogs.index'),
        buildQueryParams(overrides),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

// Permission checks
const { hasPermission } = usePermissions();
const createBlogs = computed(() => hasPermission('blogs.acp.create'));
const editBlogs = computed(() => hasPermission('blogs.acp.edit'));
const publishBlogs = computed(() => hasPermission('blogs.acp.publish'));
const deleteBlogs = computed(() => hasPermission('blogs.acp.delete'));
const manageTags = computed(() => createBlogs.value || editBlogs.value);
const manageCategories = computed(() => createBlogs.value || editBlogs.value);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blogs ACP',
        href: '/acp/blogs',
    },
];

// Expect that the admin controller passes a "blogs" (paginated collection) & "blogStats" prop
type PaginationLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

type SortOption = 'created_desc' | 'created_asc' | 'views_desc' | 'views_asc';

type TrendingPost = {
    id: number;
    title: string;
    slug: string;
    label: string;
    views: number;
    last_viewed_at: string | null;
    published_at: string | null;
};

const props = defineProps<{
    blogs: {
        data: Array<{
            id: number;
            title: string;
            slug: string;
            user: {
                id: number;
                nickname: string;
                email: string;
            } | null;
            status: string;
            created_at: string | null;
            scheduled_for: string | null;
            views: number;
            last_viewed_at: string | null;
        }>;
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    blogStats: {
        total: number;
        published: number;
        draft: number;
        scheduled: number;
        archived: number;
        total_views: number;
        viewed_last_30_days: number;
    };
    filters: {
        search: string | null;
        status: string[] | null;
        sort: SortOption | null;
        min_views: number | null;
        max_views: number | null;
    };
    trendingPosts: TrendingPost[];
}>();

const hasBlogs = computed(() => (props.blogs.data?.length ?? 0) > 0);

const searchQuery = ref(props.filters.search ?? '');
const statusFilters = computed(() => props.filters.status ?? []);
let skipSearchWatch = false;
const defaultSortOption: SortOption = 'created_desc';
const sortOption = ref<SortOption>(props.filters.sort ?? defaultSortOption);
const minViews = ref(
    typeof props.filters.min_views === 'number' ? String(props.filters.min_views) : '',
);
const maxViews = ref(
    typeof props.filters.max_views === 'number' ? String(props.filters.max_views) : '',
);
let skipSortWatch = false;
let skipViewsWatch = false;

const trendingPosts = computed<TrendingPost[]>(() => props.trendingPosts ?? []);
const hasTrendingData = computed(() => trendingPosts.value.length > 0);
const trendingChartData = computed(() =>
    trendingPosts.value.map((post) => ({
        label: post.label,
        Views: post.views,
    })),
);
const trendingChartCategories = ['Views'];

const {
    meta: blogsMeta,
    page: blogsPage,
    setPage: setBlogsPage,
    rangeLabel: blogsRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.blogs.meta ?? null),
    itemsLength: computed(() => props.blogs.data?.length ?? 0),
    defaultPerPage: 15,
    itemLabel: 'blog post',
    itemLabelPlural: 'blog posts',
    onNavigate: (page) => {
        navigateWithFilters({ page });
    },
});

// Blog statistics with Lucide icons
const stats = computed(() => [
    { title: 'Total Posts', value: props.blogStats.total, icon: FileText },
    { title: 'Published Posts', value: props.blogStats.published, icon: CheckCircle },
    { title: 'Draft Posts', value: props.blogStats.draft, icon: Edit3 },
    { title: 'Scheduled Posts', value: props.blogStats.scheduled, icon: CalendarClock },
    { title: 'Archived Posts', value: props.blogStats.archived, icon: Archive },
    { title: 'All-time Views', value: props.blogStats.total_views, icon: Eye },
    { title: 'Posts viewed (30 days)', value: props.blogStats.viewed_last_30_days, icon: TrendingUp },
]);

// Search query for filtering blog posts
type BlogRow = {
    id: number;
    title: string;
    slug: string;
    user: {
        id: number;
        nickname: string;
        email: string;
    } | null;
    status: string;
    created_at: string | null;
    scheduled_for: string | null;
    views: number;
    last_viewed_at: string | null;
};

const blogRows = computed<BlogRow[]>(() => props.blogs.data ?? []);

type CheckboxState = CheckboxRootProps['checked'];

const selectedBlogIds = ref<number[]>([]);

watch(
    blogRows,
    (rows) => {
        const validIds = new Set(rows.map((row) => row.id));
        selectedBlogIds.value = selectedBlogIds.value.filter((id) => validIds.has(id));
    },
    { immediate: true },
);

const hasBlogSelection = computed(() => selectedBlogIds.value.length > 0);
const allBlogsSelected = computed(
    () => blogRows.value.length > 0 && selectedBlogIds.value.length === blogRows.value.length,
);
const blogHeaderCheckboxState = computed<CheckboxState>(() => {
    if (allBlogsSelected.value) {
        return true;
    }

    if (selectedBlogIds.value.length > 0) {
        return 'indeterminate';
    }

    return false;
});

const blogSelectionLabel = computed(() => {
    if (!publishBlogs.value) {
        return 'Bulk actions require publish access.';
    }

    const count = selectedBlogIds.value.length;

    if (count === 0) {
        return 'Select blog posts to enable bulk actions.';
    }

    return count === 1 ? '1 blog selected.' : `${count} blogs selected.`;
});

const bulkBlogForm = useForm<{ ids: number[]; action: 'publish' | 'unpublish' | 'archive' | 'unarchive' }>({
    ids: [],
    action: 'publish',
});

const updateBlogSelection = (blogId: number, checked: boolean) => {
    if (checked) {
        if (!selectedBlogIds.value.includes(blogId)) {
            selectedBlogIds.value = [...selectedBlogIds.value, blogId];
        }

        return;
    }

    selectedBlogIds.value = selectedBlogIds.value.filter((id) => id !== blogId);
};

const toggleAllBlogs = (checked: boolean) => {
    if (checked) {
        selectedBlogIds.value = blogRows.value.map((row) => row.id);

        return;
    }

    selectedBlogIds.value = [];
};

const submitBulkBlogAction = (action: 'publish' | 'unpublish' | 'archive' | 'unarchive') => {
    const ids = Array.from(new Set(selectedBlogIds.value));

    if (ids.length === 0) {
        return;
    }

    bulkBlogForm.ids = ids;
    bulkBlogForm.action = action;

    bulkBlogForm.patch(route('acp.blogs.bulk-status'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedBlogIds.value = [];
        },
    });
};

const debouncedSearch = useDebounceFn(() => {
    setBlogsPage(1, { emitNavigate: false });
    navigateWithFilters({ page: 1, search: searchQuery.value });
}, 300);

const debouncedViewsFilter = useDebounceFn(() => {
    setBlogsPage(1, { emitNavigate: false });
    navigateWithFilters({ page: 1 });
}, 300);

watch(
    () => props.filters.search ?? '',
    (value) => {
        if (searchQuery.value === value) {
            return;
        }

        skipSearchWatch = true;
        searchQuery.value = value;
    },
);

watch(searchQuery, () => {
    if (skipSearchWatch) {
        skipSearchWatch = false;
        return;
    }

    debouncedSearch();
});

watch(
    () => props.filters.sort ?? defaultSortOption,
    (value) => {
        const normalized = (value ?? defaultSortOption) as SortOption;

        if (sortOption.value === normalized) {
            return;
        }

        skipSortWatch = true;
        sortOption.value = normalized;
    },
);

watch(sortOption, (value) => {
    if (skipSortWatch) {
        skipSortWatch = false;
        return;
    }

    setBlogsPage(1, { emitNavigate: false });
    navigateWithFilters({ page: 1, sort: value });
});

watch(
    () => [props.filters.min_views, props.filters.max_views],
    ([min, max]) => {
        const normalizedMin = typeof min === 'number' ? String(min) : '';
        const normalizedMax = typeof max === 'number' ? String(max) : '';

        if (minViews.value === normalizedMin && maxViews.value === normalizedMax) {
            return;
        }

        skipViewsWatch = true;
        minViews.value = normalizedMin;
        maxViews.value = normalizedMax;
    },
);

watch([minViews, maxViews], () => {
    if (skipViewsWatch) {
        skipViewsWatch = false;
        return;
    }

    debouncedViewsFilter();
});

const publishPost = (postId: number) => {
    router.put(route('acp.blogs.publish', { blog: postId }), {}, {
        preserveScroll: true,
    });
};

const unpublishPost = (postId: number) => {
    router.put(route('acp.blogs.unpublish', { blog: postId }), {}, {
        preserveScroll: true,
    });
};

const {
    confirmDialogState,
    confirmDialogDescription,
    openConfirmDialog,
    handleConfirmDialogConfirm,
    handleConfirmDialogCancel,
} = useConfirmDialog();

const confirmArchivePost = (post: BlogRow) => {
    openConfirmDialog({
        title: `Archive “${post.title}”?`,
        description:
            'Archiving this post will hide it from the public blog listing. You can restore it from the admin panel at any time.',
        confirmLabel: 'Archive post',
        onConfirm: () => {
            router.put(route('acp.blogs.archive', { blog: post.id }), {}, {
                preserveScroll: true,
            });
        },
    });
};

const unarchivePost = (postId: number) => {
    router.put(route('acp.blogs.unarchive', { blog: postId }), {}, {
        preserveScroll: true,
    });
};

const deletePost = (postId: number) => {
    router.delete(route('acp.blogs.destroy', { blog: postId }), {
        preserveScroll: true,
    });
};

const confirmDeletePost = (post: BlogRow) => {
    openConfirmDialog({
        title: `Delete “${post.title}”?`,
        description: 'Deleting this post will permanently remove it and its content from the site.',
        confirmLabel: 'Delete post',
        onConfirm: () => deletePost(post.id),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Blogs ACP" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Blog Stats Section -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in stats"
                        :key="index"
                        class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ formatNumber(stat.value) }}</div>
                        </div>

                        <PlaceholderPattern />
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="flex items-center gap-2 text-lg font-semibold">
                                <TrendingUp class="h-5 w-5 text-muted-foreground" />
                                Trending posts
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Top-performing articles from the past 30 days based on view counts.
                            </p>
                        </div>
                        <div class="text-sm text-muted-foreground">
                            {{ formatNumber(props.blogStats.total_views) }} total views recorded
                        </div>
                    </div>

                    <LineChart
                        v-if="hasTrendingData"
                        :data="trendingChartData"
                        index="label"
                        :categories="trendingChartCategories"
                        :show-legend="false"
                        :curve-type="CurveType.Linear"
                        :y-formatter="(tick) => (typeof tick === 'number' ? formatNumber(tick) : '')"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        We will chart trends here once posts accumulate enough views.
                    </p>

                    <ul
                        v-if="hasTrendingData"
                        class="mt-4 grid gap-3 md:grid-cols-2"
                    >
                        <li
                            v-for="post in trendingPosts"
                            :key="post.id"
                            class="rounded-lg border border-sidebar-border/60 p-3 text-sm dark:border-sidebar-border"
                        >
                            <div class="flex flex-col gap-2">
                                <Link
                                    :href="route('blogs.view', { slug: post.slug })"
                                    class="font-medium text-primary hover:underline"
                                >
                                    {{ post.title }}
                                </Link>
                                <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <span>{{ formatNumber(post.views) }} views</span>
                                    <span v-if="post.last_viewed_at">• Last read {{ fromNow(post.last_viewed_at) }}</span>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Blog Posts Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="mb-4 space-y-3">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <h2 class="text-lg font-semibold">Blog Posts</h2>
                            <div class="flex flex-wrap justify-end gap-2">
                                <Link v-if="manageCategories" :href="route('acp.blog-categories.index')">
                                    <Button variant="outline" class="text-sm">
                                        Manage Categories
                                    </Button>
                                </Link>
                                <Link v-if="manageTags" :href="route('acp.blog-tags.index')">
                                    <Button variant="outline" class="text-sm">
                                        Manage Tags
                                    </Button>
                                </Link>
                                <Link v-if="createBlogs" :href="route('acp.blogs.create')">
                                    <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                        Create New Post
                                    </Button>
                                </Link>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:gap-2">
                            <Input
                                v-model="searchQuery"
                                placeholder="Search blogs..."
                                class="w-full rounded-md md:w-64"
                                aria-label="Search blog posts"
                            />
                            <label class="w-full md:w-48 text-sm">
                                <span class="sr-only">Sort blog posts</span>
                                <select
                                    v-model="sortOption"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                >
                                    <option value="created_desc">Newest first</option>
                                    <option value="created_asc">Oldest first</option>
                                    <option value="views_desc">Most viewed</option>
                                    <option value="views_asc">Least viewed</option>
                                </select>
                            </label>
                            <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:gap-2">
                                <Input
                                    v-model="minViews"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    placeholder="Min views"
                                    aria-label="Filter by minimum views"
                                    class="w-full rounded-md sm:w-32"
                                />
                                <Input
                                    v-model="maxViews"
                                    type="number"
                                    min="0"
                                    inputmode="numeric"
                                    placeholder="Max views"
                                    aria-label="Filter by maximum views"
                                    class="w-full rounded-md sm:w-32"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <p class="text-sm text-muted-foreground">{{ blogSelectionLabel }}</p>
                        <DropdownMenu v-if="publishBlogs">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="outline"
                                    :disabled="!hasBlogSelection || bulkBlogForm.processing"
                                >
                                    Bulk status
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuLabel>Set blog status</DropdownMenuLabel>
                                <DropdownMenuItem
                                    :disabled="bulkBlogForm.processing"
                                    @select="submitBulkBlogAction('publish')"
                                >
                                    <Eye class="mr-2 h-4 w-4" />
                                    <span>Publish now</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :disabled="bulkBlogForm.processing"
                                    @select="submitBulkBlogAction('unpublish')"
                                >
                                    <EyeOff class="mr-2 h-4 w-4" />
                                    <span>Move to draft</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :disabled="bulkBlogForm.processing"
                                    @select="submitBulkBlogAction('archive')"
                                >
                                    <Archive class="mr-2 h-4 w-4" />
                                    <span>Archive posts</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :disabled="bulkBlogForm.processing"
                                    @select="submitBulkBlogAction('unarchive')"
                                >
                                    <ArchiveRestore class="mr-2 h-4 w-4" />
                                    <span>Restore to draft</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-12">
                                        <Checkbox
                                            :checked="blogHeaderCheckboxState"
                                            :disabled="!publishBlogs || blogRows.length === 0"
                                            aria-label="Select all blog posts"
                                            @update:checked="toggleAllBlogs"
                                        />
                                    </TableHead>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Title</TableHead>
                                    <TableHead class="text-center">Author</TableHead>
                                    <TableHead class="text-center">Created</TableHead>
                                    <TableHead class="text-center">Views</TableHead>
                                    <TableHead class="text-center">Last viewed</TableHead>
                                    <TableHead class="text-center">Status</TableHead>
                                    <TableHead class="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="(post) in blogRows" :key="post.id">
                                    <TableCell class="align-middle">
                                        <Checkbox
                                            :checked="selectedBlogIds.includes(post.id)"
                                            :disabled="!publishBlogs"
                                            aria-label="Select blog post"
                                            @update:checked="(checked) => updateBlogSelection(post.id, checked)"
                                        />
                                    </TableCell>
                                    <TableCell>{{ post.id }}</TableCell>
                                    <TableCell>{{ post.title }}</TableCell>
                                    <TableCell class="text-center">{{ post.user?.nickname ?? '—' }}</TableCell>
                                    <TableCell class="text-center">{{ post.created_at ? fromNow(post.created_at) : '—' }}</TableCell>
                                    <TableCell class="text-center">{{ formatNumber(post.views) }}</TableCell>
                                    <TableCell class="text-center">{{ post.last_viewed_at ? fromNow(post.last_viewed_at) : '—' }}</TableCell>
                                    <TableCell class="text-center" :class="{
                                        'text-green-500': post.status === 'published',
                                        'text-red-500': post.status === 'archived',
                                        'text-blue-500': post.status === 'draft',
                                        'text-amber-500': post.status === 'scheduled',
                                    }">
                                        {{ post.status }}</TableCell>
                                    <TableCell class="text-center">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button variant="outline" size="icon">
                                                    <Ellipsis class="h-8 w-8" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator v-if="publishBlogs" />
                                                <DropdownMenuGroup v-if="publishBlogs">
                                                    <DropdownMenuItem
                                                        v-if="post.status === 'draft' || post.status === 'scheduled'"
                                                        @click="publishPost(post.id)"
                                                    >
                                                        <Eye class="mr-2" />
                                                        <span>Publish now</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="post.status === 'published'"
                                                        @click="unpublishPost(post.id)"
                                                    >
                                                        <EyeOff class="mr-2" />
                                                        <span>Unpublish</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="post.status === 'scheduled'"
                                                        @click="unpublishPost(post.id)"
                                                    >
                                                        <EyeOff class="mr-2" />
                                                        <span>Unschedule</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="post.status === 'archived'"
                                                        @click="unarchivePost(post.id)"
                                                    >
                                                        <ArchiveRestore class="mr-2" />
                                                        <span>Unarchive</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="post.status !== 'archived'"
                                                        @click="confirmArchivePost(post)"
                                                    >
                                                        <Archive class="mr-2" />
                                                        <span>Archive</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="editBlogs" />
                                                <DropdownMenuGroup v-if="editBlogs">
                                                    <Link :href="route('acp.blogs.edit', { blog: post.id })">
                                                        <DropdownMenuItem class="text-blue-500">
                                                            <Pencil class="mr-2" />
                                                            <span>Edit</span>
                                                        </DropdownMenuItem>
                                                    </Link>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="deleteBlogs" />
                                                <DropdownMenuItem
                                                    v-if="deleteBlogs"
                                                    class="text-red-500"
                                                    @click="confirmDeletePost(post)"
                                                >
                                                    <Trash2 class="mr-2" />
                                                    <span>Delete</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="blogRows.length === 0">
                                    <TableCell colspan="9" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                        No blog posts found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <!-- Bottom Pagination -->
                <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                    <div class="text-sm text-muted-foreground text-center md:text-left">
                        {{ blogsRangeLabel }}
                    </div>
                    <Pagination
                        v-if="hasBlogs || blogsMeta.total > 0"
                        v-slot="{ page, pageCount }"
                        v-model:page="blogsPage"
                        :items-per-page="Math.max(blogsMeta.per_page, 1)"
                        :total="blogsMeta.total"
                        :sibling-count="1"
                        show-edges
                    >
                        <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                            <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                            <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                <PaginationFirst />
                                <PaginationPrev />

                                <template v-for="(item, index) in items" :key="index">
                                    <PaginationListItem
                                        v-if="item.type === 'page'"
                                        :value="item.value"
                                        as-child
                                    >
                                        <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                            {{ item.value }}
                                        </Button>
                                    </PaginationListItem>
                                    <PaginationEllipsis
                                        v-else
                                        :index="index"
                                    />
                                </template>

                                <PaginationNext />
                                <PaginationLast />
                            </PaginationList>
                        </div>
                    </Pagination>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
    <ConfirmDialog
        v-model:open="confirmDialogState.open"
        :title="confirmDialogState.title"
        :description="confirmDialogDescription"
        :confirm-label="confirmDialogState.confirmLabel"
        :cancel-label="confirmDialogState.cancelLabel"
        :confirm-variant="confirmDialogState.confirmVariant"
        :confirm-disabled="confirmDialogState.confirmDisabled"
        @confirm="handleConfirmDialogConfirm"
        @cancel="handleConfirmDialogCancel"
    />
</template>
