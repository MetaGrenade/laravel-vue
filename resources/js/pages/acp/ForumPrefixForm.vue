<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import ForumPrefixBadge from '@/components/forum/ForumPrefixBadge.vue';

interface PrefixForm {
    id: number;
    name: string;
    slug: string;
    color: string;
    position: number;
    board_ids: number[];
}

interface BoardChoice {
    id: number;
    title: string;
}

interface CategoryChoice {
    id: number;
    title: string;
    boards: BoardChoice[];
}

const props = defineProps<{
    prefix: PrefixForm | null;
    colors: string[];
    categories: CategoryChoice[];
}>();

const editing = computed(() => props.prefix !== null);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Thread prefixes', href: route('acp.forums.prefixes.index') },
    {
        title: editing.value ? 'Edit prefix' : 'New prefix',
        href: editing.value ? route('acp.forums.prefixes.edit', { prefix: props.prefix?.id }) : route('acp.forums.prefixes.create'),
    },
]);

const colorLabels: Record<string, string> = {
    slate: 'Slate',
    sky: 'Sky',
    green: 'Green',
    amber: 'Amber',
    rose: 'Rose',
    violet: 'Violet',
};

const form = useForm({
    name: props.prefix?.name ?? '',
    slug: props.prefix?.slug ?? '',
    color: props.prefix?.color ?? props.colors[0] ?? 'slate',
    position: props.prefix?.position ?? 0,
    boards: [...(props.prefix?.board_ids ?? [])],
});

const preview = computed(() => ({ name: form.name.trim() || 'Prefix', color: form.color }));

const submit = () => {
    if (editing.value && props.prefix) {
        form.put(route('acp.forums.prefixes.update', { prefix: props.prefix.id }));
        return;
    }

    form.post(route('acp.forums.prefixes.store'));
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="editing ? 'Edit prefix' : 'New prefix'" />

        <AdminLayout>
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ editing ? 'Edit prefix' : 'New prefix' }}</h1>
                        <p class="text-sm text-muted-foreground">The name is what members see beside a thread title. Keep it short.</p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.forums.prefixes.index')">Back</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">{{ editing ? 'Save changes' : 'Create prefix' }}</Button>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Label</CardTitle>
                        <CardDescription>
                            <ForumPrefixBadge :prefix="preview" />
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="prefix-name">Name</Label>
                            <Input id="prefix-name" v-model="form.name" maxlength="40" required autocomplete="off" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="prefix-slug">Slug</Label>
                            <Input id="prefix-slug" v-model="form.slug" autocomplete="off" placeholder="Leave blank to make one from the name" />
                            <InputError :message="form.errors.slug" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="prefix-color">Colour</Label>
                            <select
                                id="prefix-color"
                                v-model="form.color"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs"
                            >
                                <option v-for="color in colors" :key="color" :value="color">{{ colorLabels[color] ?? color }}</option>
                            </select>
                            <InputError :message="form.errors.color" />
                        </div>
                        <div v-if="editing" class="grid gap-2">
                            <Label for="prefix-position">Order</Label>
                            <Input id="prefix-position" v-model.number="form.position" type="number" min="0" class="max-w-32" />
                            <InputError :message="form.errors.position" />
                        </div>
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-medium">Boards that offer it</legend>
                            <p v-if="categories.every((category) => category.boards.length === 0)" class="text-sm text-muted-foreground">
                                There are no boards yet.
                            </p>
                            <div v-for="category in categories" :key="category.id" class="space-y-2">
                                <p v-if="category.boards.length" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {{ category.title }}
                                </p>
                                <label v-for="board in category.boards" :key="board.id" class="flex items-center gap-2 text-sm">
                                    <input v-model="form.boards" type="checkbox" :value="board.id" class="size-4 rounded border-input" />
                                    {{ board.title }}
                                </label>
                            </div>
                            <InputError :message="form.errors.boards" />
                        </fieldset>
                    </CardContent>
                    <CardFooter>
                        <Button type="submit" :disabled="form.processing">{{ editing ? 'Save changes' : 'Create prefix' }}</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
