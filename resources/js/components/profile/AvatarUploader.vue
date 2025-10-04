<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    currentAvatar?: string | null;
}>();

const model = defineModel<File | null>({ default: null });

const emit = defineEmits<{
    (e: 'update:preview', value: string | null): void;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const canvas = document.createElement('canvas');
const context = canvas.getContext('2d');
const cropSize = 256;
canvas.width = cropSize;
canvas.height = cropSize;

const selectedImage = ref<string | null>(null);
const previewUrl = ref<string | null>(null);
const imageEl = ref<HTMLImageElement | null>(null);
const naturalWidth = ref(0);
const naturalHeight = ref(0);
const baseScale = ref(1);
const zoom = ref(1);
const offset = ref({ x: 0, y: 0 });
const dragging = ref(false);
const pointerId = ref<number | null>(null);
const lastPointerPosition = ref({ x: 0, y: 0 });
const latestGeneration = ref(0);
const selectedFileName = ref<string>('avatar.png');

const imageStyle = computed(() => {
    const img = imageEl.value;

    if (!img) {
        return {};
    }

    const scale = baseScale.value * zoom.value;
    const width = naturalWidth.value * scale;
    const height = naturalHeight.value * scale;

    return {
        width: `${width}px`,
        height: `${height}px`,
        transformOrigin: 'center center',
        transform: `translate(-50%, -50%) translate3d(${offset.value.x}px, ${offset.value.y}px, 0)`,
    };
});

watch(
    () => model.value,
    value => {
        if (value === null && selectedImage.value) {
            resetSelection();
        }
    },
);

watch(selectedImage, value => {
    if (!value) {
        naturalWidth.value = 0;
        naturalHeight.value = 0;
        baseScale.value = 1;
        zoom.value = 1;
        offset.value = { x: 0, y: 0 };
        previewUrl.value = null;
        emit('update:preview', null);
        model.value = null;
        return;
    }

    nextTick(() => {
        const img = imageEl.value;

        if (!img) {
            return;
        }

        if (img.complete) {
            initializeImageDimensions(img);
        }
    });
});

watch(zoom, () => {
    if (!imageEl.value) {
        return;
    }

    offset.value = clampOffset(offset.value.x, offset.value.y);
    queueCropUpdate();
});

watch(
    () => offset.value,
    () => {
        if (!imageEl.value) {
            return;
        }

        queueCropUpdate();
    },
    { deep: true },
);

const hasSelection = computed(() => !!selectedImage.value);

function selectFile(): void {
    fileInput.value?.click();
}

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement | null;

    if (!input?.files?.[0]) {
        resetSelection();
        return;
    }

    const file = input.files[0];
    selectedFileName.value = file.name || 'avatar.png';
    const reader = new FileReader();

    reader.onload = () => {
        if (typeof reader.result === 'string') {
            selectedImage.value = reader.result;
        }
    };

    reader.readAsDataURL(file);
}

function onImageLoad(event: Event): void {
    const target = event.target as HTMLImageElement;
    initializeImageDimensions(target);
}

function initializeImageDimensions(img: HTMLImageElement): void {
    naturalWidth.value = img.naturalWidth;
    naturalHeight.value = img.naturalHeight;

    if (naturalWidth.value === 0 || naturalHeight.value === 0) {
        return;
    }

    const widthScale = cropSize / naturalWidth.value;
    const heightScale = cropSize / naturalHeight.value;
    baseScale.value = Math.max(widthScale, heightScale);
    zoom.value = 1;
    offset.value = { x: 0, y: 0 };

    queueCropUpdate();
}

function onPointerDown(event: PointerEvent): void {
    if (!hasSelection.value || dragging.value) {
        return;
    }

    event.preventDefault();
    dragging.value = true;
    pointerId.value = event.pointerId;
    lastPointerPosition.value = { x: event.clientX, y: event.clientY };
    (event.target as HTMLElement).setPointerCapture(event.pointerId);
}

function onPointerMove(event: PointerEvent): void {
    if (!dragging.value || pointerId.value !== event.pointerId) {
        return;
    }

    const deltaX = event.clientX - lastPointerPosition.value.x;
    const deltaY = event.clientY - lastPointerPosition.value.y;
    lastPointerPosition.value = { x: event.clientX, y: event.clientY };

    offset.value = clampOffset(offset.value.x + deltaX, offset.value.y + deltaY);
}

function onPointerUp(event: PointerEvent): void {
    if (!dragging.value || pointerId.value !== event.pointerId) {
        return;
    }

    dragging.value = false;
    pointerId.value = null;
    (event.target as HTMLElement).releasePointerCapture(event.pointerId);
}

function clampOffset(x: number, y: number): { x: number; y: number } {
    const img = imageEl.value;

    if (!img) {
        return { x, y };
    }

    const scale = baseScale.value * zoom.value;
    const scaledWidth = naturalWidth.value * scale;
    const scaledHeight = naturalHeight.value * scale;
    const maxX = Math.max(0, (scaledWidth - cropSize) / 2);
    const maxY = Math.max(0, (scaledHeight - cropSize) / 2);

    return {
        x: Math.min(maxX, Math.max(-maxX, x)),
        y: Math.min(maxY, Math.max(-maxY, y)),
    };
}

function resetSelection(): void {
    selectedImage.value = null;
    selectedFileName.value = 'avatar.png';
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function queueCropUpdate(): void {
    if (!context || !imageEl.value) {
        return;
    }

    const generation = ++latestGeneration.value;

    requestAnimationFrame(() => {
        if (!imageEl.value || generation !== latestGeneration.value) {
            return;
        }

        const scale = baseScale.value * zoom.value;
        const scaledWidth = naturalWidth.value * scale;
        const scaledHeight = naturalHeight.value * scale;
        const drawX = (cropSize - scaledWidth) / 2 + offset.value.x;
        const drawY = (cropSize - scaledHeight) / 2 + offset.value.y;

        context.clearRect(0, 0, cropSize, cropSize);
        context.fillStyle = '#fff';
        context.fillRect(0, 0, cropSize, cropSize);
        context.drawImage(
            imageEl.value,
            0,
            0,
            naturalWidth.value,
            naturalHeight.value,
            drawX,
            drawY,
            scaledWidth,
            scaledHeight,
        );

        const dataUrl = canvas.toDataURL('image/png');
        previewUrl.value = dataUrl;
        emit('update:preview', dataUrl);

        canvas.toBlob(blob => {
            if (!blob || generation !== latestGeneration.value) {
                return;
            }

            const file = new File([blob], selectedFileName.value.replace(/\.[^/.]+$/, '') + '.png', {
                type: 'image/png',
            });

            model.value = file;
        }, 'image/png');
    });
}
</script>

<template>
    <div class="space-y-3">
        <input
            ref="fileInput"
            type="file"
            accept="image/*"
            class="hidden"
            @change="onFileChange"
        />

        <div class="flex flex-col gap-6 lg:flex-row">
            <div class="flex flex-col items-center gap-3">
                <div
                    class="relative h-48 w-48 overflow-hidden rounded-full border bg-muted"
                    :class="hasSelection ? (dragging ? 'cursor-grabbing' : 'cursor-grab') : ''"
                >
                    <div
                        v-if="hasSelection"
                        class="relative h-full w-full"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="onPointerUp"
                        @pointerleave="onPointerUp"
                    >
                        <img
                            ref="imageEl"
                            :src="selectedImage"
                            alt="Selected avatar"
                            class="pointer-events-none absolute left-1/2 top-1/2 select-none"
                            draggable="false"
                            :style="imageStyle"
                            @load="onImageLoad"
                        />
                    </div>
                    <img
                        v-else-if="props.currentAvatar"
                        :src="props.currentAvatar"
                        alt="Current avatar"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="flex h-full w-full items-center justify-center text-sm text-muted-foreground">
                        No avatar selected
                    </div>
                    <div
                        v-if="hasSelection"
                        class="pointer-events-none absolute inset-0 rounded-full border border-white/70 ring-2 ring-white/30"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <Button type="button" size="sm" @click="selectFile">
                        Choose image
                    </Button>
                    <Button
                        v-if="hasSelection"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="resetSelection"
                    >
                        Remove
                    </Button>
                </div>
            </div>

            <div class="flex-1 space-y-4">
                <div v-if="previewUrl" class="space-y-2">
                    <Label>Preview</Label>
                    <img :src="previewUrl" alt="Avatar preview" class="h-24 w-24 rounded-full border object-cover" />
                </div>
                <div v-else-if="props.currentAvatar" class="space-y-2">
                    <Label>Current preview</Label>
                    <img
                        :src="props.currentAvatar"
                        alt="Avatar preview"
                        class="h-24 w-24 rounded-full border object-cover"
                    />
                </div>

                <div v-if="hasSelection" class="space-y-2">
                    <Label for="avatar_zoom">Zoom</Label>
                    <input
                        id="avatar_zoom"
                        v-model.number="zoom"
                        type="range"
                        min="1"
                        max="3"
                        step="0.01"
                        class="w-full"
                    />
                </div>

                <p class="text-xs text-muted-foreground">
                    Upload a square image (PNG, JPG, GIF, or WebP, up to 2 MB). Drag to reposition your avatar and use the
                    slider to adjust the zoom level before saving.
                </p>
            </div>
        </div>
    </div>
</template>
