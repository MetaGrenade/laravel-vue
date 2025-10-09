<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import InputError from '@/components/InputError.vue';
import { cn } from '@/lib/utils';

interface Props {
    modelValue: File | null;
    preview?: string | null;
    disabled?: boolean;
    error?: string;
    label?: string;
    description?: string;
    removeLabel?: string;
}

const props = withDefaults(defineProps<Props>(), {
    preview: null,
    disabled: false,
    error: undefined,
    label: 'Avatar',
    description: '',
    removeLabel: 'Remove avatar',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: File | null): void;
    (e: 'preview-change', value: string | null): void;
    (e: 'clear-error'): void;
    (e: 'remove-avatar'): void;
}>();

interface ImageDimensions {
    width: number;
    height: number;
}

const cropSize = 240;
const maxZoom = 4;

const fileInput = ref<HTMLInputElement | null>(null);
const containerRef = ref<HTMLDivElement | null>(null);
const selectedFile = ref<File | null>(null);
const rawImageUrl = ref<string | null>(null);
const imageDimensions = ref<ImageDimensions | null>(null);
const zoom = ref(1);
const outputSize = ref(128);
const localPreview = ref<string | null>(props.preview ?? null);
const managedPreviewUrl = ref<string | null>(null);

const position = reactive({ x: 0, y: 0 });
const focalPoint = reactive({ x: 0.5, y: 0.5 });
const dragState = ref<{
    pointerId: number;
    startX: number;
    startY: number;
    originX: number;
    originY: number;
} | null>(null);

const objectUrls = new Set<string>();

watch(
    () => props.preview,
    value => {
        if (value === localPreview.value) {
            return;
        }

        if (managedPreviewUrl.value && managedPreviewUrl.value === localPreview.value) {
            releaseObjectUrl(managedPreviewUrl.value);
            managedPreviewUrl.value = null;
        }

        localPreview.value = value ?? null;
    },
    { immediate: true },
);

watch(
    () => props.modelValue,
    value => {
        if (!value) {
            selectedFile.value = null;
            return;
        }

        if (selectedFile.value === value) {
            return;
        }

        selectedFile.value = value;
    },
    { immediate: true },
);

watch(rawImageUrl, async value => {
    if (!value) {
        imageDimensions.value = null;
        zoom.value = 1;
        position.x = 0;
        position.y = 0;
        return;
    }

    const dimensions = await loadImageDimensions(value);
    imageDimensions.value = dimensions;
    zoom.value = 1;
    await nextTick();
    resetPosition();
});

watch(zoom, nextZoom => {
    if (!imageDimensions.value || !rawImageUrl.value) {
        return;
    }

    const nextSize = calculateDisplaySize(nextZoom);
    const nextPosition = positionFromFocalPoint(focalPoint, nextSize);

    position.x = nextPosition.x;
    position.y = nextPosition.y;
    updateFocalPoint();
});

const displayedPreview = computed(() => props.preview ?? localPreview.value);
const croppingActive = computed(() => rawImageUrl.value !== null && imageDimensions.value !== null);
const displaySize = computed(() => calculateDisplaySize(zoom.value));

const zoomPercentage = computed(() => Math.round(zoom.value * 100));
const cropAreaStyle = computed(() => ({
    width: `${cropSize}px`,
    height: `${cropSize}px`,
}));

const imageStyle = computed(() => {
    if (!imageDimensions.value) {
        return {};
    }

    const { width, height } = displaySize.value;

    return {
        width: `${width}px`,
        height: `${height}px`,
        transform: `translate(${position.x}px, ${position.y}px)`,
    };
});

const outputSizeOptions = [96, 128, 160, 192, 224, 256];

function openFileDialog() {
    fileInput.value?.click();
}

function releaseObjectUrl(url: string | null) {
    if (!url) {
        return;
    }

    if (objectUrls.has(url)) {
        URL.revokeObjectURL(url);
        objectUrls.delete(url);
    }
}

async function handleFileSelection(event: Event) {
    if (props.disabled) {
        return;
    }

    const input = event.target as HTMLInputElement | null;
    const files = input?.files;

    if (!files || files.length === 0) {
        return;
    }

    const [file] = files;

    selectedFile.value = file;
    rawImageUrl.value = registerObjectUrl(file);
    emit('clear-error');
    emit('update:modelValue', null);

    if (input) {
        input.value = '';
    }
}

function registerObjectUrl(source: Blob): string {
    const url = URL.createObjectURL(source);
    objectUrls.add(url);
    return url;
}

function resetPosition() {
    if (!imageDimensions.value) {
        position.x = 0;
        position.y = 0;
        focalPoint.x = 0.5;
        focalPoint.y = 0.5;
        return;
    }

    const { width, height } = displaySize.value;

    focalPoint.x = 0.5;
    focalPoint.y = 0.5;

    const initial = positionFromFocalPoint(focalPoint, { width, height });

    position.x = initial.x;
    position.y = initial.y;
    updateFocalPoint();
}

function calculateDisplaySize(currentZoom: number) {
    if (!imageDimensions.value) {
        return { width: 0, height: 0 };
    }

    const baseScale = Math.max(
        cropSize / imageDimensions.value.width,
        cropSize / imageDimensions.value.height,
    );

    return {
        width: imageDimensions.value.width * baseScale * currentZoom,
        height: imageDimensions.value.height * baseScale * currentZoom,
    };
}

function clampPosition(candidate: { x: number; y: number }, size = displaySize.value) {
    const { width, height } = size;

    const minX = Math.min(0, cropSize - width);
    const maxX = Math.max(0, 0);
    const minY = Math.min(0, cropSize - height);
    const maxY = Math.max(0, 0);

    return {
        x: Math.min(maxX, Math.max(minX, candidate.x)),
        y: Math.min(maxY, Math.max(minY, candidate.y)),
    };
}

function beginDrag(event: PointerEvent) {
    if (!croppingActive.value || props.disabled) {
        return;
    }

    const target = containerRef.value;

    if (!target) {
        return;
    }

    dragState.value = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        originX: position.x,
        originY: position.y,
    };

    target.setPointerCapture(event.pointerId);
    event.preventDefault();
}

function drag(event: PointerEvent) {
    if (!dragState.value) {
        return;
    }

    const deltaX = event.clientX - dragState.value.startX;
    const deltaY = event.clientY - dragState.value.startY;

    const nextPosition = clampPosition({
        x: dragState.value.originX + deltaX,
        y: dragState.value.originY + deltaY,
    });

    position.x = nextPosition.x;
    position.y = nextPosition.y;
    updateFocalPoint();
}

function endDrag(event: PointerEvent) {
    if (!dragState.value || dragState.value.pointerId !== event.pointerId) {
        return;
    }

    const target = containerRef.value;

    if (target) {
        target.releasePointerCapture(event.pointerId);
    }

    dragState.value = null;
}

async function applyCrop() {
    if (!croppingActive.value || !selectedFile.value) {
        return;
    }

    const { width: displayWidth } = displaySize.value;

    if (displayWidth === 0) {
        return;
    }

    const scaleRatio = imageDimensions.value!.width / displayWidth;

    const sourceX = Math.max(0, -position.x * scaleRatio);
    const sourceY = Math.max(0, -position.y * scaleRatio);
    const sourceSize = cropSize * scaleRatio;

    const canvas = document.createElement('canvas');
    canvas.width = outputSize.value;
    canvas.height = outputSize.value;

    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    context.imageSmoothingQuality = 'high';

    const image = await loadImage(rawImageUrl.value!);

    context.drawImage(
        image,
        sourceX,
        sourceY,
        sourceSize,
        sourceSize,
        0,
        0,
        outputSize.value,
        outputSize.value,
    );

    const outputType = normalizeMimeType(selectedFile.value.type);
    const quality = outputType === 'image/jpeg' ? 0.92 : 1;

    const blob = await new Promise<Blob | null>(resolve => {
        canvas.toBlob(resolve, outputType, quality);
    });

    if (!blob) {
        return;
    }

    const generatedFile = new File([blob], buildFileName(selectedFile.value.name, blob.type), {
        type: blob.type,
    });

    emit('update:modelValue', generatedFile);

    if (managedPreviewUrl.value && managedPreviewUrl.value !== props.preview) {
        releaseObjectUrl(managedPreviewUrl.value);
    }

    const previewUrl = registerObjectUrl(blob);
    managedPreviewUrl.value = previewUrl;
    localPreview.value = previewUrl;

    emit('preview-change', previewUrl);

    releaseObjectUrl(rawImageUrl.value);
    rawImageUrl.value = null;
    selectedFile.value = generatedFile;
}

function cancelCrop() {
    if (!croppingActive.value) {
        return;
    }

    releaseObjectUrl(rawImageUrl.value);
    rawImageUrl.value = null;
    selectedFile.value = null;
    focalPoint.x = 0.5;
    focalPoint.y = 0.5;
}

function clearAvatar() {
    emit('update:modelValue', null);
    emit('preview-change', null);
    emit('remove-avatar');

    if (managedPreviewUrl.value) {
        releaseObjectUrl(managedPreviewUrl.value);
        managedPreviewUrl.value = null;
    }

    if (localPreview.value) {
        releaseObjectUrl(localPreview.value);
    }

    localPreview.value = null;
    cancelCrop();
}

function normalizeMimeType(type: string) {
    if (type === 'image/png' || type === 'image/jpeg' || type === 'image/webp' || type === 'image/gif') {
        return type;
    }

    if (type === 'image/jpg') {
        return 'image/jpeg';
    }

    return 'image/png';
}

function buildFileName(originalName: string, mimeType: string) {
    const extension = mimeTypeToExtension(mimeType);
    const baseName = originalName.replace(/\.[^/.]+$/, '');
    const safeBase = baseName.trim() !== '' ? baseName.trim().replace(/[^a-zA-Z0-9_-]+/g, '-') : 'avatar';

    return `${safeBase}-${Date.now()}.${extension}`;
}

function mimeTypeToExtension(mimeType: string) {
    switch (mimeType) {
        case 'image/jpeg':
            return 'jpg';
        case 'image/png':
            return 'png';
        case 'image/webp':
            return 'webp';
        case 'image/gif':
            return 'gif';
        default:
            return 'png';
    }
}

function formatOutputSizeLabel(size: number) {
    return `${size} × ${size}`;
}

function positionFromFocalPoint(point: { x: number; y: number }, size: ImageDimensions) {
    const candidate = {
        x: cropSize / 2 - size.width * point.x,
        y: cropSize / 2 - size.height * point.y,
    };

    return clampPosition(candidate, size);
}

function updateFocalPoint() {
    const { width, height } = displaySize.value;

    if (width === 0 || height === 0) {
        return;
    }

    const centerX = -position.x + cropSize / 2;
    const centerY = -position.y + cropSize / 2;

    focalPoint.x = clamp01(centerX / width);
    focalPoint.y = clamp01(centerY / height);
}

function clamp01(value: number) {
    if (Number.isNaN(value)) {
        return 0.5;
    }

    return Math.min(1, Math.max(0, value));
}

async function loadImage(url: string) {
    const image = new Image();
    image.src = url;
    image.crossOrigin = 'anonymous';

    if (image.complete) {
        return image;
    }

    return await new Promise<HTMLImageElement>((resolve, reject) => {
        image.onload = () => resolve(image);
        image.onerror = reject;
    });
}

async function loadImageDimensions(url: string) {
    const image = await loadImage(url);
    return {
        width: image.width,
        height: image.height,
    };
}

onBeforeUnmount(() => {
    releaseObjectUrl(rawImageUrl.value);
    releaseObjectUrl(managedPreviewUrl.value);
    releaseObjectUrl(localPreview.value);

    objectUrls.forEach(url => URL.revokeObjectURL(url));
    objectUrls.clear();
});
</script>

<template>
    <div class="space-y-3">
        <div class="space-y-2">
            <label class="text-sm font-medium leading-none">{{ label }}</label>
            <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
        </div>

        <div class="flex flex-col gap-6 lg:flex-row">
            <div class="flex flex-col items-start gap-3">
                <Avatar class="h-24 w-24">
                    <AvatarImage v-if="displayedPreview" :src="displayedPreview" alt="Avatar preview" />
                    <AvatarFallback>AV</AvatarFallback>
                </Avatar>

                <div class="flex items-center gap-2">
                    <Button type="button" variant="outline" size="sm" :disabled="disabled" @click="openFileDialog">
                        Choose image
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled || (!displayedPreview && !croppingActive)"
                        @click="clearAvatar"
                    >
                        {{ removeLabel }}
                    </Button>
                </div>

                <input
                    ref="fileInput"
                    type="file"
                    accept="image/png,image/jpeg,image/jpg,image/webp,image/gif"
                    class="hidden"
                    @change="handleFileSelection"
                />
            </div>

            <div class="flex-1 space-y-4">
                <div v-if="croppingActive" class="space-y-4">
                    <div
                        ref="containerRef"
                        class="relative overflow-hidden rounded-md border bg-muted"
                        :style="cropAreaStyle"
                        @pointerdown="beginDrag"
                        @pointermove="drag"
                        @pointerup="endDrag"
                        @pointercancel="endDrag"
                        @pointerleave="endDrag"
                    >
                        <div
                            class="absolute inset-0 pointer-events-none"
                            aria-hidden="true"
                            style="box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);"
                        />
                        <img
                            v-if="rawImageUrl"
                            :src="rawImageUrl"
                            alt="Crop preview"
                            class="pointer-events-none select-none absolute left-0 top-0"
                            :style="imageStyle"
                            draggable="false"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-xs font-medium text-muted-foreground">Zoom ({{ zoomPercentage }}%)</label>
                        <input
                            type="range"
                            min="1"
                            :max="maxZoom"
                            step="0.01"
                            v-model.number="zoom"
                            class="w-full"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="text-xs font-medium text-muted-foreground">Output size</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="size in outputSizeOptions"
                                :key="`output-size-${size}`"
                                type="button"
                                class="rounded-md border px-3 py-1 text-xs font-medium transition-colors"
                                :class="cn(
                                    'border-border bg-background hover:bg-accent hover:text-accent-foreground',
                                    outputSize === size && 'border-primary bg-primary/5 text-primary',
                                )"
                                :disabled="disabled"
                                @click="outputSize = size"
                            >
                                {{ formatOutputSizeLabel(size) }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Button type="button" size="sm" :disabled="disabled" @click="applyCrop">Apply crop</Button>
                        <Button type="button" size="sm" variant="secondary" :disabled="disabled" @click="cancelCrop">
                            Cancel
                        </Button>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Upload a square image in PNG, JPG, GIF, or WEBP format. Use the cropping tools to frame your avatar and
                    resize the final image before saving.
                </p>
                <InputError :message="error" />
            </div>
        </div>
    </div>
</template>
