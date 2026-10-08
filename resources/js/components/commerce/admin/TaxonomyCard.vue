<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Capabilities, TaxonomyEntry } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<{
    /** The route's {type}: brands, categories or tags. */
    type: 'brands' | 'categories' | 'tags';
    title: string;
    /** One of them, lower case, for sentences ("brand"). */
    noun: string;
    description: string;
    entries: TaxonomyEntry[];
    /** What deleting does to the products that use one. */
    deleteNote: string;
    can: Capabilities;
}>();

const open = ref(false);
const editing = ref<TaxonomyEntry | null>(null);
const form = useForm({ name: '', slug: '', description: '' });

const openNew = () => {
    editing.value = null;
    form.defaults({ name: '', slug: '', description: '' }).reset();
    form.clearErrors();
    open.value = true;
};

const openEdit = (entry: TaxonomyEntry) => {
    editing.value = entry;
    form.defaults({ name: entry.name, slug: entry.slug, description: entry.description ?? '' }).reset();
    form.clearErrors();
    open.value = true;
};

const save = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(route('acp.commerce.taxonomy.update', [props.type, editing.value.id]), options);
    } else {
        form.post(route('acp.commerce.taxonomy.store', props.type), options);
    }
};

const pendingDelete = ref<TaxonomyEntry | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.taxonomy.destroy', [props.type, pendingDelete.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};
</script>

<template>
    <Card>
        <CardHeader class="flex flex-row items-start justify-between gap-4">
            <div>
                <CardTitle>{{ title }}</CardTitle>
                <CardDescription>{{ description }}</CardDescription>
            </div>
            <Button v-if="can.create" size="sm" @click="openNew"><Plus class="size-4" /> Add</Button>
        </CardHeader>
        <CardContent>
            <ul v-if="entries.length" class="divide-y">
                <li v-for="entry in entries" :key="entry.id" class="flex items-center justify-between gap-3 py-2.5">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ entry.name }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ entry.slug }} · {{ entry.products_count }} {{ entry.products_count === 1 ? 'product' : 'products' }}
                        </p>
                    </div>
                    <div v-if="can.edit || can.delete" class="flex shrink-0 gap-1">
                        <Button v-if="can.edit" variant="ghost" size="icon" :aria-label="`Edit ${entry.name}`" @click="openEdit(entry)">
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            v-if="can.delete"
                            variant="ghost"
                            size="icon"
                            class="text-destructive"
                            :aria-label="`Delete ${entry.name}`"
                            @click="pendingDelete = entry"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">None yet.</p>
        </CardContent>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ editing ? `Edit ${noun}` : `Add a ${noun}` }}</DialogTitle>
                    <DialogDescription>Shown in the shop's filters and on product pages.</DialogDescription>
                </DialogHeader>

                <form :id="`${type}-form`" class="space-y-5" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label :for="`${type}-name`">Name</Label>
                        <Input :id="`${type}-name`" v-model="form.name" maxlength="255" :aria-invalid="!!form.errors.name || undefined" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${type}-slug`">Web address <span class="text-muted-foreground">(optional)</span></Label>
                        <Input
                            :id="`${type}-slug`"
                            v-model="form.slug"
                            maxlength="255"
                            placeholder="Made from the name"
                            :aria-invalid="!!form.errors.slug || undefined"
                        />
                        <InputError :message="form.errors.slug" />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${type}-description`">Description <span class="text-muted-foreground">(optional)</span></Label>
                        <Textarea :id="`${type}-description`" v-model="form.description" rows="3" maxlength="1000" />
                        <InputError :message="form.errors.description" />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" :form="`${type}-form`" :disabled="form.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="pendingDelete !== null"
            :title="`Delete ${pendingDelete?.name ?? `this ${noun}`}?`"
            :description="deleteNote"
            :confirm-label="`Delete ${noun}`"
            @update:open="(value) => !value && (pendingDelete = null)"
            @confirm="remove"
            @cancel="pendingDelete = null"
        />
    </Card>
</template>
