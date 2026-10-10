<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { formatBytes } from '@/lib/bytes';
import type { Capabilities, ProductFileRow } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/vue3';
import { FilePlus, RefreshCw, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    productId: number;
    files: ProductFileRow[];
    rules: { max_count: number; max_kilobytes: number; limit: number; expires_after_days: number };
    requiresShipping: boolean;
    can: Capabilities;
}>();

const megabytes = computed(() => Math.round((props.rules.max_kilobytes / 1024) * 10) / 10);
const room = computed(() => props.rules.max_count - props.files.length);

// ----- Upload ------------------------------------------------------------------------------------

const form = useForm<{ files: File[] }>({ files: [] });
const picker = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

const upload = (chosen: FileList | File[] | null) => {
    const files = Array.from(chosen ?? []);

    if (!files.length || room.value <= 0) {
        return;
    }

    form.files = files;
    form.post(route('acp.commerce.files.store', props.productId), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            form.files = [];

            if (picker.value) {
                picker.value.value = '';
            }
        },
    });
};

const onPick = (event: Event) => upload((event.target as HTMLInputElement).files);

const onDrop = (event: DragEvent) => {
    dragging.value = false;
    upload(event.dataTransfer?.files ?? null);
};

// A refused upload is reported per field (files.0 for a rule, files for a file that could not be saved).
const uploadErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .flatMap(([, message]) => String(message).split('\n'))
        .filter(Boolean),
);

// ----- Label, switch on and off, replace, delete -------------------------------------------------

// What is typed in each label box, until it is saved.
const drafts = ref<Record<number, string>>({});

const draft = (file: ProductFileRow) => drafts.value[file.id] ?? file.name;

const save = (file: ProductFileRow, changes: { name?: string; is_active?: boolean }) => {
    const name = (changes.name ?? draft(file)).trim();
    const is_active = changes.is_active ?? file.is_active;

    if (name === file.name && is_active === file.is_active) {
        return;
    }

    router.put(
        route('acp.commerce.files.update', file.id),
        { name: name === '' ? file.name : name, is_active },
        { preserveScroll: true, preserveState: true },
    );
};

const replacing = ref<ProductFileRow | null>(null);
const replacePicker = ref<HTMLInputElement | null>(null);
const replaceError = ref<string | null>(null);

const chooseReplacement = (file: ProductFileRow) => {
    replacing.value = file;
    replaceError.value = null;
    replacePicker.value?.click();
};

const onReplace = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0];
    const target = replacing.value;

    if (!chosen || !target) {
        return;
    }

    router.post(
        route('acp.commerce.files.replace', target.id),
        { file: chosen },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => (replaceError.value = Object.values(errors).join(' ')),
            onFinish: () => {
                replacing.value = null;
                input.value = '';
            },
        },
    );
};

const pendingDelete = ref<ProductFileRow | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.files.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-muted-foreground">
            Files this product delivers once an order is paid. They are kept privately and handed out only through the order page, at most
            <template v-if="rules.limit > 0">{{ rules.limit }} {{ rules.limit === 1 ? 'time' : 'times' }} each</template>
            <template v-else>as often as the customer likes</template
            ><template v-if="rules.expires_after_days > 0">, for {{ rules.expires_after_days }} days after payment</template>. A product that is not
            shipped and has files is completed as soon as it is paid.
        </p>
        <p v-if="requiresShipping && files.length" class="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
            This product is also shipped. If it is only a download, switch off "Needs shipping" on the Details tab so orders complete as soon as they
            are paid.
        </p>

        <div v-if="can.create">
            <label
                for="product-files"
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-6 text-center transition-colors"
                :class="[
                    dragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/60',
                    room <= 0 || form.processing ? 'pointer-events-none opacity-60' : '',
                ]"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <FilePlus class="size-8 text-muted-foreground" aria-hidden="true" />
                <span class="font-medium">{{ form.processing ? 'Uploading…' : 'Drop files here, or choose files' }}</span>
                <span class="text-xs text-muted-foreground">
                    Any kind of file, up to {{ megabytes }} MB each.
                    {{ room > 0 ? `Room for ${room} more.` : 'This product has the most files it can.' }}
                </span>
                <input
                    id="product-files"
                    ref="picker"
                    type="file"
                    class="sr-only"
                    multiple
                    :disabled="room <= 0 || form.processing"
                    @change="onPick"
                />
            </label>

            <div
                v-if="form.progress"
                class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="form.progress.percentage"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="h-full bg-primary transition-all" :style="{ width: `${form.progress.percentage}%` }" />
            </div>

            <ul v-if="uploadErrors.length" class="mt-2 space-y-1" role="alert">
                <li v-for="message in uploadErrors" :key="message"><InputError :message="message" /></li>
            </ul>
        </div>

        <input ref="replacePicker" type="file" class="sr-only" tabindex="-1" aria-hidden="true" @change="onReplace" />
        <p v-if="replaceError" class="text-sm text-destructive" role="alert">{{ replaceError }}</p>

        <ul v-if="files.length" class="divide-y rounded-lg border">
            <li v-for="file in files" :key="file.id" class="space-y-3 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="grid min-w-0 flex-1 gap-1">
                        <label :for="`file-name-${file.id}`" class="text-xs font-medium">Name shown to the customer</label>
                        <Input
                            :id="`file-name-${file.id}`"
                            :model-value="draft(file)"
                            maxlength="255"
                            :disabled="!can.edit"
                            @update:model-value="(value) => (drafts[file.id] = String(value))"
                            @blur="save(file, {})"
                            @keydown.enter.prevent="save(file, {})"
                        />
                    </div>
                    <div class="flex items-center gap-3 pt-5">
                        <Badge v-if="!file.is_active" variant="secondary">Switched off</Badge>
                        <div v-if="can.edit" class="flex items-center gap-2">
                            <Switch
                                :id="`file-active-${file.id}`"
                                :model-value="file.is_active"
                                @update:model-value="(value) => save(file, { is_active: Boolean(value) })"
                            />
                            <label :for="`file-active-${file.id}`" class="text-sm">On</label>
                        </div>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Saved as <span class="font-mono">{{ file.original_name }}</span> · {{ formatBytes(file.size) }}
                    <template v-if="file.mime"> · {{ file.mime }}</template>
                </p>
                <p class="font-mono text-xs break-all text-muted-foreground">SHA-256 {{ file.sha256 }}</p>

                <div v-if="can.edit || can.delete" class="flex flex-wrap items-center gap-2">
                    <Button v-if="can.edit" variant="outline" size="sm" :disabled="replacing !== null" @click="chooseReplacement(file)">
                        <RefreshCw class="size-4" /> Upload a new version
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="sm"
                        class="ml-auto text-destructive"
                        :aria-label="`Delete ${file.name}`"
                        @click="pendingDelete = file"
                    >
                        <Trash2 class="size-4" /> Delete
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            No files yet. Add the files customers receive when they buy this product.
        </p>

        <ConfirmDialog
            :open="pendingDelete !== null"
            title="Delete this file?"
            description="It is deleted for good, and customers who bought this product lose access to it. To stop offering it for a while, switch it off instead."
            confirm-label="Delete file"
            @update:open="(value) => !value && (pendingDelete = null)"
            @confirm="remove"
            @cancel="pendingDelete = null"
        />
    </div>
</template>
