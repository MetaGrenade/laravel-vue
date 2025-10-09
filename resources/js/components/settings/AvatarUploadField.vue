<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';

interface Props {
    modelValue: File | null;
    preview?: string | null;
    disabled?: boolean;
    error?: string;
    label?: string;
    description?: string;
    removeLabel?: string;
    chooseLabel?: string;
}

const props = withDefaults(defineProps<Props>(), {
    preview: null,
    disabled: false,
    error: undefined,
    label: 'Avatar',
    description: 'Upload a square image between 96px and 256px (PNG, JPG, GIF, or WEBP).',
    removeLabel: 'Remove avatar',
    chooseLabel: 'Choose image',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: File | null): void;
    (e: 'preview-change', value: string | null): void;
    (e: 'clear-error'): void;
    (e: 'remove-avatar'): void;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const managedPreviewUrl = ref<string | null>(null);

const previewSource = computed(() => managedPreviewUrl.value ?? props.preview ?? null);
const hasPreview = computed(() => !!previewSource.value);
const fallbackInitial = computed(() => props.label?.trim().charAt(0).toUpperCase() ?? 'A');

function openFileDialog() {
    if (props.disabled) {
        return;
    }

    fileInput.value?.click();
}

function handleFileChange(event: Event) {
    const input = event.target as HTMLInputElement | null;
    const files = input?.files;
    const file = files && files.length ? files[0] : null;

    if (!file) {
        return;
    }

    const objectUrl = URL.createObjectURL(file);

    setManagedPreview(objectUrl);
    emit('update:modelValue', file);
    emit('preview-change', objectUrl);
    emit('clear-error');

    if (input) {
        input.value = '';
    }
}

function setManagedPreview(url: string) {
    releaseManagedPreview();
    managedPreviewUrl.value = url;
}

function releaseManagedPreview() {
    if (!managedPreviewUrl.value) {
        return;
    }

    URL.revokeObjectURL(managedPreviewUrl.value);
    managedPreviewUrl.value = null;
}

function removeAvatar() {
    if (props.disabled) {
        return;
    }

    releaseManagedPreview();
    emit('update:modelValue', null);
    emit('preview-change', null);
    emit('clear-error');
    emit('remove-avatar');

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

watch(
    () => props.modelValue,
    value => {
        if (!value) {
            releaseManagedPreview();
        }
    },
);

onBeforeUnmount(() => {
    releaseManagedPreview();
});
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-4 rounded-md border border-dashed p-4">
            <Avatar class="h-16 w-16">
                <AvatarImage v-if="hasPreview" :src="previewSource ?? undefined" alt="" />
                <AvatarFallback>{{ fallbackInitial }}</AvatarFallback>
            </Avatar>

            <div class="flex flex-1 flex-col gap-2">
                <span class="text-sm font-medium text-foreground">{{ props.label }}</span>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="props.disabled"
                        @click="openFileDialog"
                    >
                        {{ props.chooseLabel }}
                    </Button>
                    <Button
                        v-if="hasPreview"
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="text-destructive hover:text-destructive"
                        :disabled="props.disabled"
                        @click="removeAvatar"
                    >
                        {{ props.removeLabel }}
                    </Button>
                </div>
                <p v-if="props.description" class="text-xs text-muted-foreground">
                    {{ props.description }}
                </p>
            </div>
        </div>

        <InputError :message="props.error" />

        <input
            ref="fileInput"
            type="file"
            accept="image/png,image/jpeg,image/jpg,image/gif,image/webp"
            class="hidden"
            :disabled="props.disabled"
            @change="handleFileChange"
        />
    </div>
</template>
