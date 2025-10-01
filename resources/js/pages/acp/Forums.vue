<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import {
    Folder, MessageSquare, CheckCircle, Ellipsis, Eye, EyeOff, Shield,
    Trash2, MoveUp, MoveDown, Pencil, MessageSquareShare, Layers,
    PlusCircle, ExternalLink
} from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import type { SharedData } from '@/types';
import { toast } from 'vue-sonner';

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
    access_permission: string | null;
    is_published: boolean;
    position: number;
    boards: ForumBoardSummary[];
};

const props = defineProps<{
    stats: ForumStat[];
    categories: ForumCategorySummary[];
}>();

const { hasPermission } = usePermissions();
const { fromNow } = useUserTimezone();
const page = usePage<SharedData & { flash?: { success?: string | null; error?: string | null } }>();

watch(
    () => page.props.flash?.success ?? null,
    (message) => {
        if (message) {
            toast.success(message);
        }
    },
);

watch(
    () => page.props.flash?.error ?? null,
    (message) => {
        if (message) {
            toast.error(message);
        }
    },
);

const createForums = computed<boolean>(() => hasPermission('forums.acp.create'));
const editForums = computed<boolean>(() => hasPermission('forums.acp.edit'));
const moveForums = computed<boolean>(() => hasPermission('forums.acp.move'));
const deleteForums = computed<boolean>(() => hasPermission('forums.acp.delete'));
const permissionsForums = computed<boolean>(() => hasPermission('forums.acp.permissions'));
const publishForums = computed<boolean>(() => hasPermission('forums.acp.publish'));
const migrateForums = computed<boolean>(() => hasPermission('forums.acp.migrate'));

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

const toggleCategoryPublish = (category: ForumCategorySummary, shouldPublish: boolean) => {
    const routeName = shouldPublish
        ? 'acp.forums.categories.publish'
        : 'acp.forums.categories.unpublish';

    router.patch(route(routeName, { category: category.id }), {}, {
        preserveScroll: true,
        onError: () => toast.error('Unable to update the publication state for this category.'),
    });
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

const permissionDialogOpen = ref(false);
const permissionDialogCategory = ref<ForumCategorySummary | null>(null);
const permissionDialogValue = ref('');
const permissionDialogProcessing = ref(false);

watch(permissionDialogOpen, (open) => {
    if (!open) {
        permissionDialogCategory.value = null;
        permissionDialogValue.value = '';
        permissionDialogProcessing.value = false;
    }
});

const openPermissionDialog = (category: ForumCategorySummary) => {
    permissionDialogCategory.value = category;
    permissionDialogValue.value = category.access_permission ?? '';
    permissionDialogOpen.value = true;
};

const submitPermissionDialog = () => {
    if (!permissionDialogCategory.value) {
        return;
    }

    permissionDialogProcessing.value = true;
    const trimmed = permissionDialogValue.value.trim();

    router.patch(
        route('acp.forums.categories.permissions', { category: permissionDialogCategory.value.id }),
        { access_permission: trimmed === '' ? null : trimmed },
        {
            preserveScroll: true,
            onSuccess: () => {
                permissionDialogOpen.value = false;
            },
            onError: () =>
                toast.error('Unable to update the category permissions. Please try again.'),
            onFinish: () => {
                permissionDialogProcessing.value = false;
            },
        },
    );
};

const migrateDialogOpen = ref(false);
const migrateDialogCategory = ref<ForumCategorySummary | null>(null);
const migrateDialogTargetId = ref<number | null>(null);
const migrateDialogProcessing = ref(false);

const availableMigrateTargets = computed<ForumCategorySummary[]>(() => {
    if (!migrateDialogCategory.value) {
        return [];
    }

    return categories.value.filter((item) => item.id !== migrateDialogCategory.value?.id);
});

watch(availableMigrateTargets, (targets) => {
    if (!migrateDialogOpen.value) {
        return;
    }

    if (targets.length === 0) {
        migrateDialogTargetId.value = null;
        return;
    }

    if (!targets.some((item) => item.id === migrateDialogTargetId.value)) {
        migrateDialogTargetId.value = targets[0]?.id ?? null;
    }
});

watch(migrateDialogOpen, (open) => {
    if (!open) {
        migrateDialogCategory.value = null;
        migrateDialogTargetId.value = null;
        migrateDialogProcessing.value = false;
    }
});

const openMigrateDialog = (category: ForumCategorySummary) => {
    const targets = categories.value.filter((item) => item.id !== category.id);

    if (targets.length === 0) {
        toast.error('There are no other categories available to migrate the boards into.');
        return;
    }

    migrateDialogCategory.value = category;
    migrateDialogTargetId.value = targets[0]?.id ?? null;
    migrateDialogOpen.value = true;
};

const submitMigrateDialog = () => {
    if (!migrateDialogCategory.value || !migrateDialogTargetId.value) {
        toast.error('Please choose a valid category to migrate into.');
        return;
    }

    migrateDialogProcessing.value = true;

    router.patch(
        route('acp.forums.categories.migrate', { category: migrateDialogCategory.value.id }),
        { target_category_id: migrateDialogTargetId.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                migrateDialogOpen.value = false;
            },
            onError: () =>
                toast.error('Unable to migrate the boards at this time. Please try again.'),
            onFinish: () => {
                migrateDialogProcessing.value = false;
            },
        },
    );
};

const deleteCategoryDialogOpen = ref(false);
const deleteCategoryDialogCategory = ref<ForumCategorySummary | null>(null);
const deleteCategoryDialogProcessing = ref(false);

watch(deleteCategoryDialogOpen, (open) => {
    if (!open) {
        deleteCategoryDialogCategory.value = null;
        deleteCategoryDialogProcessing.value = false;
    }
});

const openDeleteCategoryDialog = (category: ForumCategorySummary) => {
    deleteCategoryDialogCategory.value = category;
    deleteCategoryDialogOpen.value = true;
};

const confirmDeleteCategory = () => {
    if (!deleteCategoryDialogCategory.value) {
        return;
    }

    deleteCategoryDialogProcessing.value = true;

    router.delete(route('acp.forums.categories.destroy', { category: deleteCategoryDialogCategory.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            deleteCategoryDialogOpen.value = false;
        },
        onError: () =>
            toast.error('Unable to delete the category right now. Please try again.'),
        onFinish: () => {
            deleteCategoryDialogProcessing.value = false;
        },
    });
};

const deleteBoardDialogOpen = ref(false);
const deleteBoardDialogTarget = ref<{ board: ForumBoardSummary; category: ForumCategorySummary } | null>(null);
const deleteBoardDialogProcessing = ref(false);

watch(deleteBoardDialogOpen, (open) => {
    if (!open) {
        deleteBoardDialogTarget.value = null;
        deleteBoardDialogProcessing.value = false;
    }
});

const openDeleteBoardDialog = (category: ForumCategorySummary, board: ForumBoardSummary) => {
    deleteBoardDialogTarget.value = { board, category };
    deleteBoardDialogOpen.value = true;
};

const confirmDeleteBoard = () => {
    if (!deleteBoardDialogTarget.value) {
        return;
    }

    deleteBoardDialogProcessing.value = true;

    router.delete(route('acp.forums.boards.destroy', { board: deleteBoardDialogTarget.value.board.id }), {
        preserveScroll: true,
        onSuccess: () => {
            deleteBoardDialogOpen.value = false;
        },
        onError: () => toast.error('Unable to delete the board right now. Please try again.'),
        onFinish: () => {
            deleteBoardDialogProcessing.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forums ACP" />

        <Dialog v-model:open="permissionDialogOpen">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitPermissionDialog">
                    <DialogHeader>
                        <DialogTitle>Configure category permissions</DialogTitle>
                        <DialogDescription v-if="permissionDialogCategory">
                            Choose the permission required to view
                            "{{ permissionDialogCategory.title }}". Leave the field blank to remove restrictions.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="category_permission_key">Permission key</Label>
                        <Input
                            id="category_permission_key"
                            v-model="permissionDialogValue"
                            type="text"
                            autocomplete="off"
                            placeholder="forums.view.private"
                            :disabled="permissionDialogProcessing"
                        />
                        <p class="text-xs text-muted-foreground">
                            Members must have this permission to access the category.
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="permissionDialogProcessing"
                            @click="permissionDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="permissionDialogProcessing">
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="migrateDialogOpen">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitMigrateDialog">
                    <DialogHeader>
                        <DialogTitle>Migrate boards to another category</DialogTitle>
                        <DialogDescription v-if="migrateDialogCategory">
                            All boards within "{{ migrateDialogCategory.title }}" will be moved into the selected
                            category.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="migrate_target_category">Target category</Label>
                        <select
                            id="migrate_target_category"
                            v-model.number="migrateDialogTargetId"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                            :disabled="migrateDialogProcessing"
                        >
                            <option
                                v-for="target in availableMigrateTargets"
                                :key="target.id"
                                :value="target.id"
                            >
                                {{ target.title }}
                            </option>
                        </select>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="migrateDialogProcessing"
                            @click="migrateDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="migrateDialogProcessing || !migrateDialogTargetId">
                            Migrate boards
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteCategoryDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete category</DialogTitle>
                    <DialogDescription v-if="deleteCategoryDialogCategory">
                        Deleting "{{ deleteCategoryDialogCategory.title }}" will remove all boards, threads, and posts it
                        contains. This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2 sm:gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="deleteCategoryDialogProcessing"
                        @click="deleteCategoryDialogOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="deleteCategoryDialogProcessing"
                        @click="confirmDeleteCategory"
                    >
                        Delete category
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteBoardDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete board</DialogTitle>
                    <DialogDescription v-if="deleteBoardDialogTarget">
                        Are you sure you want to delete "{{ deleteBoardDialogTarget.board.title }}" from
                        "{{ deleteBoardDialogTarget.category.title }}"? All threads and posts within the board will be
                        permanently removed.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2 sm:gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="deleteBoardDialogProcessing"
                        @click="deleteBoardDialogOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="deleteBoardDialogProcessing"
                        @click="confirmDeleteBoard"
                    >
                        Delete board
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

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
                                <div
                                    v-if="category.access_permission || !category.is_published"
                                    class="mt-2 flex flex-wrap gap-2"
                                >
                                    <span
                                        v-if="!category.is_published"
                                        class="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200"
                                    >
                                        Unpublished
                                    </span>
                                    <span
                                        v-if="category.access_permission"
                                        class="inline-flex items-center rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-800 dark:bg-blue-500/20 dark:text-blue-200"
                                    >
                                        Requires "{{ category.access_permission }}"
                                    </span>
                                </div>
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
                                    <DropdownMenuGroup v-if="permissionsForums">
                                        <DropdownMenuItem @select="openPermissionDialog(category)">
                                            <Shield class="h-4 w-4" />
                                            <span>Configure Permissions</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuGroup v-if="publishForums">
                                        <DropdownMenuItem
                                            v-if="category.is_published"
                                            @select="toggleCategoryPublish(category, false)"
                                        >
                                            <EyeOff class="h-4 w-4" />
                                            <span>Unpublish</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-else
                                            @select="toggleCategoryPublish(category, true)"
                                        >
                                            <Eye class="h-4 w-4" />
                                            <span>Publish</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator v-if="migrateForums" />
                                    <DropdownMenuGroup v-if="migrateForums">
                                        <DropdownMenuItem @select="openMigrateDialog(category)">
                                            <MessageSquareShare class="h-4 w-4" />
                                            <span>Migrate Boards</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator v-if="deleteForums" />
                                    <DropdownMenuItem
                                        v-if="deleteForums"
                                        class="text-red-500"
                                        @select="openDeleteCategoryDialog(category)"
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
                                                @select="openDeleteBoardDialog(category, board)"
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
