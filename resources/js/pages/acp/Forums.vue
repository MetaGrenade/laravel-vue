<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import {
    Folder, MessageSquare, CheckCircle, Ellipsis, EyeOff, Shield,
    Trash2, MoveUp, MoveDown, Pencil, MessageSquareShare, Lock
} from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';

type ForumStat = {
    title: string;
    value: number;
};

type ForumBoardSummary = {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    position: number;
    thread_count: number;
    post_count: number;
    latest_post: {
        title: string;
        author: { id: number; nickname: string } | null;
        posted_at: string | null;
    } | null;
};

type ForumCategorySummary = {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    position: number;
    boards: ForumBoardSummary[];
};

const props = defineProps<{
    stats: ForumStat[];
    categories: ForumCategorySummary[];
}>();

const { hasPermission } = usePermissions();
const { fromNow } = useUserTimezone();

const createForums = computed(() => hasPermission('forums.acp.create'));
const editForums = computed(() => hasPermission('forums.acp.edit'));
const moveForums = computed(() => hasPermission('forums.acp.move'));
const deleteForums = computed(() => hasPermission('forums.acp.delete'));

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Forums ACP',
        href: route('acp.forums.index'),
    },
];

const statIconMap = {
    'Total Categories': Folder,
    'Total Boards': Layers,
    'Total Threads': MessageSquare,
    'Total Posts': CheckCircle,
} as const;

const defaultStatIcons = [Folder, Layers, MessageSquare, CheckCircle];

const forumStats = computed(() =>
    props.stats.map((stat, index) => ({
        ...stat,
        icon: statIconMap[stat.title as keyof typeof statIconMap] ?? defaultStatIcons[index] ?? Folder,
    })),
);

const categories = computed(() => props.categories);
const hasCategories = computed(() => categories.value.length > 0);

const formatRelative = (value: string | null | undefined) => {
    if (!value) {
        return null;
    }

    return fromNow(value);
};

const reorderCategory = (categoryId: number, direction: 'up' | 'down') => {
    router.patch(
        route('acp.forums.categories.reorder', { category: categoryId }),
        { direction },
        { preserveScroll: true },
    );
};

const goToCategoryEdit = (categoryId: number) => {
    router.get(route('acp.forums.categories.edit', { category: categoryId }));
};

const deleteCategory = (categoryId: number) => {
    if (
        confirm(
            'Deleting this category will also remove all boards, threads, and posts within it. Are you sure you want to continue?',
        )
    ) {
        router.delete(route('acp.forums.categories.destroy', { category: categoryId }), {
            preserveScroll: true,
        });
    }
};

const openBoardCreate = (categoryId: number) => {
    router.get(route('acp.forums.boards.create'), { category: categoryId });
};

const reorderBoard = (boardId: number, direction: 'up' | 'down') => {
    router.patch(
        route('acp.forums.boards.reorder', { board: boardId }),
        { direction },
        { preserveScroll: true },
    );
};

const goToBoardEdit = (boardId: number) => {
    router.get(route('acp.forums.boards.edit', { board: boardId }));
};

const deleteBoard = (boardId: number) => {
    if (
        confirm('Deleting this board will remove all threads and posts it contains. Do you want to proceed?')
    ) {
        router.delete(route('acp.forums.boards.destroy', { board: boardId }), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forums ACP" />

        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in forumStats"
                        :key="index"
                        class="relative flex items-center overflow-hidden rounded-xl border border-sidebar-border/70 p-4"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ stat.value }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between pb-4">
                        <div>
                            <h2 class="mb-2 text-xl font-bold">Manage Forum Categories</h2>
                            <p class="text-sm text-muted-foreground">
                                Create, arrange, and update the categories and boards that power the community forums.
                            </p>
                        </div>
                        <Button
                            v-if="createForums"
                            variant="success"
                            class="text-sm text-white bg-green-500 hover:bg-green-600"
                            as-child
                        >
                            <Link :href="route('acp.forums.categories.create')" preserve-scroll>
                                <PlusCircle class="mr-2 h-4 w-4" />
                                Create Category
                            </Link>
                        </Button>
                    </div>

                    <p
                        v-if="!hasCategories"
                        class="rounded-lg border border-dashed border-sidebar-border/70 p-6 text-center text-sm text-muted-foreground"
                    >
                        No forum categories have been created yet. Use the button above to add your first category.
                    </p>

                    <div
                        v-for="(category, catIndex) in categories"
                        v-else
                        :key="category.id"
                        class="mb-6 rounded-lg border border-sidebar-border/70 shadow transition hover:shadow-lg"
                    >
                        <div class="flex items-start justify-between rounded-t-lg bg-gray-100 p-4 dark:bg-neutral-900">
                            <div>
                                <h3 class="text-xl font-bold">{{ category.title }}</h3>
                                <p v-if="category.description" class="mt-1 text-sm text-muted-foreground">
                                    {{ category.description }}
                                </p>
                            </div>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="outline" size="icon">
                                        <Ellipsis class="h-8 w-8" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent>
                                    <DropdownMenuLabel>Category Actions</DropdownMenuLabel>
                                    <DropdownMenuGroup v-if="moveForums">
                                        <DropdownMenuItem
                                            :disabled="catIndex === 0"
                                            @select="reorderCategory(category.id, 'up')"
                                        >
                                            <MoveUp class="h-4 w-4" />
                                            <span>Move Up</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            :disabled="catIndex === categories.length - 1"
                                            @select="reorderCategory(category.id, 'down')"
                                        >
                                            <MoveDown class="h-4 w-4" />
                                            <span>Move Down</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuGroup v-if="createForums">
                                        <DropdownMenuItem @select="openBoardCreate(category.id)">
                                            <PlusCircle class="h-4 w-4" />
                                            <span>Add Board</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuGroup v-if="editForums">
                                        <DropdownMenuItem class="text-blue-500" @select="goToCategoryEdit(category.id)">
                                            <Pencil class="h-4 w-4" />
                                            <span>Edit Category</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator v-if="deleteForums" />
                                    <DropdownMenuItem
                                        v-if="deleteForums"
                                        class="text-red-500"
                                        @select="deleteCategory(category.id)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                        <span>Delete Category</span>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <div v-if="category.boards.length" class="divide-y">
                            <div
                                v-for="(board, boardIndex) in category.boards"
                                :key="board.id"
                                class="flex flex-col gap-3 p-4 transition hover:bg-gray-50 dark:hover:bg-neutral-800 md:flex-row md:items-center"
                            >
                                <div class="mr-4 flex items-center md:items-start">
                                    <Folder class="h-8 w-8 text-gray-600" />
                                </div>
                                <div class="flex-1">
                                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <h4 class="text-lg font-semibold">
                                                <Link
                                                    :href="route('forum.boards.show', { board: board.slug })"
                                                    class="hover:underline"
                                                >
                                                    {{ board.title }}
                                                </Link>
                                            </h4>
                                            <p v-if="board.description" class="text-sm text-muted-foreground">
                                                {{ board.description }}
                                            </p>
                                        </div>
                                        <div class="mt-3 flex gap-6 text-sm text-muted-foreground md:mt-0">
                                            <span><strong class="font-semibold">{{ board.thread_count }}</strong> Threads</span>
                                            <span><strong class="font-semibold">{{ board.post_count }}</strong> Posts</span>
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs text-muted-foreground">
                                        <template v-if="board.latest_post">
                                            Latest: <span class="font-medium">{{ board.latest_post.title }}</span>
                                            <template v-if="board.latest_post.author">
                                                by {{ board.latest_post.author.nickname }}
                                            </template>
                                            <span v-if="formatRelative(board.latest_post.posted_at)" class="ml-1">
                                                ({{ formatRelative(board.latest_post.posted_at) }})
                                            </span>
                                        </template>
                                        <template v-else>
                                            No posts yet.
                                        </template>
                                    </div>
                                </div>
                                <div class="flex w-full items-center justify-between md:w-48 md:justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="outline" size="icon">
                                                <Ellipsis class="h-8 w-8" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            <DropdownMenuLabel>Board Actions</DropdownMenuLabel>
                                            <DropdownMenuGroup>
                                                <DropdownMenuItem as-child>
                                                    <Link
                                                        :href="route('forum.boards.show', { board: board.slug })"
                                                        class="flex items-center gap-2"
                                                    >
                                                        <ExternalLink class="h-4 w-4" />
                                                        <span>View Board</span>
                                                    </Link>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuGroup v-if="moveForums">
                                                <DropdownMenuItem
                                                    :disabled="boardIndex === 0"
                                                    @select="reorderBoard(board.id, 'up')"
                                                >
                                                    <MoveUp class="h-4 w-4" />
                                                    <span>Move Up</span>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="boardIndex === category.boards.length - 1"
                                                    @select="reorderBoard(board.id, 'down')"
                                                >
                                                    <MoveDown class="h-4 w-4" />
                                                    <span>Move Down</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuGroup v-if="editForums">
                                                <DropdownMenuItem class="text-blue-500" @select="goToBoardEdit(board.id)">
                                                    <Pencil class="h-4 w-4" />
                                                    <span>Edit Board</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator v-if="deleteForums" />
                                            <DropdownMenuItem
                                                v-if="deleteForums"
                                                class="text-red-500"
                                                @select="deleteBoard(board.id)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                <span>Delete Board</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </div>
                        <div v-else class="px-4 py-6 text-sm text-muted-foreground">
                            This category does not contain any boards yet.
                            <button
                                v-if="createForums"
                                type="button"
                                class="ml-1 font-medium text-primary hover:underline"
                                @click="openBoardCreate(category.id)"
                            >
                                Add a board now.
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
