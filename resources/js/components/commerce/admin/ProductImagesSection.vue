<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Capabilities, ProductImageRow } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, ImagePlus, Star, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    productId: number;
    images: ProductImageRow[];
    rules: { max_count: number; max_kilobytes: number };
    can: Capabilities;
}>();

const megabytes = computed(() => Math.round((props.rules.max_kilobytes / 1024) * 10) / 10);
const room = computed(() => props.rules.max_count - props.images.length);

// ----- Upload ------------------------------------------------------------------------------------

const form = useForm<{ images: File[] }>({ images: [] });
const picker = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

const upload = (files: FileList | File[] | null) => {
    const chosen = Array.from(files ?? []);

    if (!chosen.length || room.value <= 0) {
        return;
    }

    form.images = chosen;
    form.post(route('acp.commerce.images.store', props.productId), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            form.images = [];

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

// A refused picture is reported per field (images.0 for a rule, images for a decoded file).
const uploadErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'images' || key.startsWith('images.'))
        .flatMap(([, message]) => String(message).split('\n'))
        .filter(Boolean),
);

// ----- Describe, arrange, delete -----------------------------------------------------------------

// What is typed in each description box, until it is saved.
const drafts = ref<Record<number, string>>({});

const draft = (image: ProductImageRow) => drafts.value[image.id] ?? image.alt ?? '';

const saveAlt = (image: ProductImageRow) => {
    const value = draft(image).trim();

    if (value === (image.alt ?? '')) {
        return;
    }

    router.put(route('acp.commerce.images.update', image.id), { alt: value }, { preserveScroll: true, preserveState: true });
};

const move = (index: number, by: -1 | 1) => {
    const ids = props.images.map((image) => image.id);
    const target = index + by;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[index], ids[target]] = [ids[target], ids[index]];
    router.post(route('acp.commerce.images.reorder', props.productId), { ids }, { preserveScroll: true });
};

const makeMain = (image: ProductImageRow) => {
    router.post(route('acp.commerce.images.main', image.id), {}, { preserveScroll: true });
};

const pendingDelete = ref<ProductImageRow | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.images.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};

const size = (bytes: number) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
</script>

<template>
    <div class="space-y-4">
        <div v-if="can.create">
            <label
                for="product-images"
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-6 text-center transition-colors"
                :class="[
                    dragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/60',
                    room <= 0 || form.processing ? 'pointer-events-none opacity-60' : '',
                ]"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <ImagePlus class="size-8 text-muted-foreground" aria-hidden="true" />
                <span class="font-medium">{{ form.processing ? 'Uploading…' : 'Drop pictures here, or choose files' }}</span>
                <span class="text-xs text-muted-foreground">
                    JPEG, PNG, WebP or GIF, up to {{ megabytes }} MB each.
                    {{ room > 0 ? `Room for ${room} more.` : 'This product has the most pictures it can.' }}
                </span>
                <input
                    id="product-images"
                    ref="picker"
                    type="file"
                    class="sr-only"
                    accept="image/jpeg,image/png,image/webp,image/gif"
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

            <p class="mt-2 text-xs text-muted-foreground">
                Pictures are resized (never enlarged) and converted to WebP, which also removes any location or camera details they carry. The
                original is not kept.
            </p>
        </div>

        <ul v-if="images.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="(image, index) in images" :key="image.id" class="space-y-2 rounded-lg border p-3">
                <div class="relative overflow-hidden rounded-md bg-muted">
                    <img
                        :src="image.url"
                        :alt="image.alt ?? ''"
                        :width="image.width"
                        :height="image.height"
                        class="aspect-[4/3] w-full object-contain"
                        loading="lazy"
                    />
                    <Badge v-if="index === 0" class="absolute top-2 left-2" variant="default">Main picture</Badge>
                </div>

                <p class="text-xs text-muted-foreground">{{ image.width }} × {{ image.height }} · {{ size(image.bytes) }}</p>

                <div class="grid gap-1">
                    <label :for="`alt-${image.id}`" class="text-xs font-medium">Description</label>
                    <Input
                        :id="`alt-${image.id}`"
                        :model-value="draft(image)"
                        maxlength="255"
                        placeholder="What the picture shows"
                        :disabled="!can.edit"
                        @update:model-value="(value) => (drafts[image.id] = String(value))"
                        @blur="saveAlt(image)"
                        @keydown.enter.prevent="saveAlt(image)"
                    />
                </div>

                <div v-if="can.edit || can.delete" class="flex flex-wrap items-center gap-1">
                    <template v-if="can.edit">
                        <Button
                            variant="ghost"
                            size="icon"
                            :disabled="index === 0"
                            :aria-label="`Move picture ${index + 1} earlier`"
                            @click="move(index, -1)"
                        >
                            <ArrowLeft class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            :disabled="index === images.length - 1"
                            :aria-label="`Move picture ${index + 1} later`"
                            @click="move(index, 1)"
                        >
                            <ArrowRight class="size-4" />
                        </Button>
                        <Button v-if="index !== 0" variant="ghost" size="sm" @click="makeMain(image)"><Star class="size-4" /> Make main</Button>
                    </template>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="icon"
                        class="ml-auto text-destructive"
                        :aria-label="`Delete picture ${index + 1}`"
                        @click="pendingDelete = image"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            No pictures yet. The first one you add is the main picture shown in the shop.
        </p>

        <ConfirmDialog
            :open="pendingDelete !== null"
            title="Delete this picture?"
            description="It is removed from the product and its files are deleted. This cannot be undone."
            confirm-label="Delete picture"
            @update:open="(value) => !value && (pendingDelete = null)"
            @confirm="remove"
            @cancel="pendingDelete = null"
        />
    </div>
</template>
