<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import Input from '@/components/ui/input/Input.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { FileText, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';

interface TemplateTeamOrCategory {
    id: number;
    name: string;
}

interface TemplateItem {
    id: number;
    title: string;
    body: string;
    is_active: boolean;
    support_ticket_category_id: number | null;
    support_team_id: number | null;
    category: TemplateTeamOrCategory | null;
    team: TemplateTeamOrCategory | null;
    created_at: string | null;
    updated_at: string | null;
}

const props = defineProps<{
    templates: TemplateItem[];
    categories: TemplateTeamOrCategory[];
    teams: TemplateTeamOrCategory[];
    can: {
        create: boolean;
        edit: boolean;
        delete: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Response templates', href: route('acp.support.templates.index') },
];

const { formatDate } = useUserTimezone();

const hasTemplates = computed(() => props.templates.length > 0);

const createForm = useForm({
    title: '',
    body: '',
    support_ticket_category_id: null as number | null,
    support_team_id: null as number | null,
    is_active: true,
});

const submitCreate = () => {
    if (!props.can.create) {
        return;
    }

    createForm.post(route('acp.support.templates.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createForm.clearErrors();
        },
    });
};

const editingTemplate = ref<TemplateItem | null>(null);
const editDialogOpen = ref(false);

const editForm = useForm({
    title: '',
    body: '',
    support_ticket_category_id: null as number | null,
    support_team_id: null as number | null,
    is_active: true,
});

const openEditDialog = (template: TemplateItem) => {
    if (!props.can.edit) {
        return;
    }

    editingTemplate.value = template;
    editForm.reset();
    editForm.clearErrors();
    editForm.title = template.title;
    editForm.body = template.body;
    editForm.support_ticket_category_id = template.support_ticket_category_id;
    editForm.support_team_id = template.support_team_id;
    editForm.is_active = template.is_active;
    editDialogOpen.value = true;
};

const closeEditDialog = () => {
    editDialogOpen.value = false;
};

watch(editDialogOpen, (open) => {
    if (!open) {
        editingTemplate.value = null;
        editForm.reset();
        editForm.clearErrors();
    }
});

const submitEdit = () => {
    const template = editingTemplate.value;

    if (!template || !props.can.edit) {
        return;
    }

    editForm.put(route('acp.support.templates.update', { template: template.id }), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditDialog();
        },
    });
};

const deleteDialogOpen = ref(false);
const pendingTemplate = ref<TemplateItem | null>(null);
const deletingTemplateId = ref<number | null>(null);

const confirmDeleteTemplate = () => {
    const template = pendingTemplate.value;

    if (!template) {
        return;
    }

    deletingTemplateId.value = template.id;
    deleteDialogOpen.value = false;
    router.delete(route('acp.support.templates.destroy', { template: template.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingTemplateId.value = null;
            pendingTemplate.value = null;
        },
    });
};

const requestDeleteTemplate = (template: TemplateItem) => {
    if (!props.can.delete) {
        return;
    }

    pendingTemplate.value = template;
    deleteDialogOpen.value = true;
};

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingTemplate.value = null;
    }
});

const deleteDialogTitle = computed(() => {
    if (!pendingTemplate.value) {
        return 'Delete template?';
    }

    return `Delete “${pendingTemplate.value.title}”?`;
});

const categoryName = (template: TemplateItem) => template.category?.name ?? 'All categories';
const teamName = (template: TemplateItem) => template.team?.name ?? 'All teams';

const cancelDeleteTemplate = () => {
    deleteDialogOpen.value = false;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage response templates" />

        <AdminLayout>
            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle class="flex items-center gap-2">
                                    <FileText class="h-5 w-5" />
                                    Response templates
                                </CardTitle>
                                <CardDescription>
                                    Maintain reusable replies for quick, consistent support communication.
                                </CardDescription>
                            </div>
                            <div v-if="props.can.create" class="hidden sm:flex">
                                <Button
                                    variant="secondary"
                                    class="flex items-center gap-2 text-white"
                                    type="submit"
                                    form="support-template-create"
                                    :disabled="createForm.processing"
                                >
                                    <PlusCircle class="h-4 w-4" />
                                    Save template
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <form
                            id="support-template-create"
                            class="grid gap-4 rounded-lg border border-border/60 p-4"
                            @submit.prevent="submitCreate"
                        >
                            <div class="grid gap-2">
                                <Label for="title">Title</Label>
                                <Input
                                    id="title"
                                    v-model="createForm.title"
                                    :disabled="createForm.processing"
                                    placeholder="e.g. Update confirmed – monitoring"
                                    required
                                />
                                <InputError :message="createForm.errors.title" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="template_body">Body</Label>
                                <Textarea
                                    id="template_body"
                                    v-model="createForm.body"
                                    :disabled="createForm.processing"
                                    class="min-h-32"
                                    placeholder="Compose the message agents will start from..."
                                    required
                                />
                                <InputError :message="createForm.errors.body" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="template_category">Category</Label>
                                    <select
                                        id="template_category"
                                        v-model="createForm.support_ticket_category_id"
                                        :disabled="createForm.processing"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option :value="null">All categories</option>
                                        <option v-for="category in props.categories" :key="category.id" :value="category.id">
                                            {{ category.name }}
                                        </option>
                                    </select>
                                    <InputError :message="createForm.errors.support_ticket_category_id" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="template_team">Team</Label>
                                    <select
                                        id="template_team"
                                        v-model="createForm.support_team_id"
                                        :disabled="createForm.processing"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option :value="null">All teams</option>
                                        <option v-for="team in props.teams" :key="team.id" :value="team.id">
                                            {{ team.name }}
                                        </option>
                                    </select>
                                    <InputError :message="createForm.errors.support_team_id" />
                                </div>
                            </div>

                            <div class="flex items-center justify-between rounded-md border border-border/60 bg-muted/40 p-3">
                                <div class="space-y-1">
                                    <span class="text-sm font-medium">Template active</span>
                                    <p class="text-xs text-muted-foreground">
                                        Toggle off to hide this template from agent reply pickers without deleting it.
                                    </p>
                                </div>
                                <Switch v-model:checked="createForm.is_active" :disabled="createForm.processing" />
                            </div>

                            <CardFooter class="justify-end px-0 pb-0">
                                <Button type="submit" :disabled="createForm.processing">Create template</Button>
                            </CardFooter>
                        </form>

                        <div>
                            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                Existing templates
                            </h3>
                            <div
                                v-if="!hasTemplates"
                                class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground"
                            >
                                No templates yet. Create one to help agents respond faster.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead class="w-1/3">Title</TableHead>
                                            <TableHead>Category</TableHead>
                                            <TableHead>Team</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Updated</TableHead>
                                            <TableHead class="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="template in props.templates" :key="template.id">
                                            <TableCell>
                                                <div class="flex flex-col gap-1">
                                                    <span class="font-medium">{{ template.title }}</span>
                                                    <span class="text-xs text-muted-foreground" :title="template.body">
                                                        {{ template.body.length > 120 ? `${template.body.slice(0, 120)}…` : template.body }}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>{{ categoryName(template) }}</TableCell>
                                            <TableCell>{{ teamName(template) }}</TableCell>
                                            <TableCell>
                                                <span
                                                    :class="[
                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                                        template.is_active
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                                            : 'bg-gray-200 text-gray-700 dark:bg-gray-800/60 dark:text-gray-300',
                                                    ]"
                                                >
                                                    {{ template.is_active ? 'Active' : 'Hidden' }}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ template.updated_at ? formatDate(template.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="flex justify-end gap-2">
                                                <Button
                                                    v-if="props.can.edit"
                                                    variant="outline"
                                                    size="sm"
                                                    @click="openEditDialog(template)"
                                                >
                                                    <Pencil class="h-4 w-4" />
                                                    Edit
                                                </Button>
                                                <Button
                                                    v-if="props.can.delete"
                                                    variant="destructive"
                                                    size="sm"
                                                    :disabled="deletingTemplateId === template.id"
                                                    @click="requestDeleteTemplate(template)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                    Delete
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                v-model:open="deleteDialogOpen"
                :title="deleteDialogTitle"
                description="This action cannot be undone."
                confirm-label="Delete"
                cancel-label="Cancel"
                :confirm-disabled="deletingTemplateId !== null"
                @confirm="confirmDeleteTemplate"
                @cancel="cancelDeleteTemplate"
            />

            <template v-if="props.can.edit">
                <Dialog v-model:open="editDialogOpen">
                    <DialogContent class="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Edit template</DialogTitle>
                            <DialogDescription>Update the canned response before publishing changes.</DialogDescription>
                        </DialogHeader>
                        <form class="mt-4 grid gap-4" @submit.prevent="submitEdit">
                            <div class="grid gap-2">
                                <Label for="edit_title">Title</Label>
                                <Input
                                    id="edit_title"
                                    v-model="editForm.title"
                                    :disabled="editForm.processing"
                                    required
                                />
                                <InputError :message="editForm.errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit_body">Body</Label>
                                <Textarea
                                    id="edit_body"
                                    v-model="editForm.body"
                                    :disabled="editForm.processing"
                                    class="min-h-32"
                                    required
                                />
                                <InputError :message="editForm.errors.body" />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="edit_category">Category</Label>
                                    <select
                                        id="edit_category"
                                        v-model="editForm.support_ticket_category_id"
                                        :disabled="editForm.processing"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option :value="null">All categories</option>
                                        <option v-for="category in props.categories" :key="category.id" :value="category.id">
                                            {{ category.name }}
                                        </option>
                                    </select>
                                    <InputError :message="editForm.errors.support_ticket_category_id" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="edit_team">Team</Label>
                                    <select
                                        id="edit_team"
                                        v-model="editForm.support_team_id"
                                        :disabled="editForm.processing"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option :value="null">All teams</option>
                                        <option v-for="team in props.teams" :key="team.id" :value="team.id">
                                            {{ team.name }}
                                        </option>
                                    </select>
                                    <InputError :message="editForm.errors.support_team_id" />
                                </div>
                            </div>
                            <div class="flex items-center justify-between rounded-md border border-border/60 bg-muted/40 p-3">
                                <div class="space-y-1">
                                    <span class="text-sm font-medium">Template active</span>
                                    <p class="text-xs text-muted-foreground">
                                        Hidden templates remain saved but disappear from the reply picker.
                                    </p>
                                </div>
                                <Switch v-model:checked="editForm.is_active" :disabled="editForm.processing" />
                            </div>
                            <CardFooter class="justify-end gap-2 px-0 pb-0">
                                <Button type="button" variant="outline" :disabled="editForm.processing" @click="closeEditDialog">
                                    Cancel
                                </Button>
                                <Button type="submit" :disabled="editForm.processing">Update template</Button>
                            </CardFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </AdminLayout>
    </AppLayout>
</template>
