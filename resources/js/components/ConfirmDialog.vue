<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useId } from 'radix-vue';

import { Button, type ButtonVariants } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        confirmVariant?: ButtonVariants['variant'];
        confirmDisabled?: boolean;
    }>(),
    {
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        confirmVariant: 'destructive',
        confirmDisabled: false,
    },
);

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
    (event: 'confirm'): void;
    (event: 'cancel'): void;
}>();

const dialogOpen = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const dialogId = useId();
const descriptionId = computed(() => (props.description ? `${dialogId}-description` : undefined));

const cancelButtonRef = ref<HTMLButtonElement | null>(null);
const confirmButtonRef = ref<HTMLButtonElement | null>(null);

watch(
    () => dialogOpen.value,
    (isOpen) => {
        if (isOpen) {
            requestAnimationFrame(() => {
                (cancelButtonRef.value ?? confirmButtonRef.value)?.focus();
            });
        }
    },
);

const handleCancel = () => {
    emit('cancel');
    dialogOpen.value = false;
};

const handleConfirm = () => {
    emit('confirm');
};
</script>

<template>
    <Dialog v-model:open="dialogOpen">
        <DialogContent role="alertdialog" :aria-describedby="descriptionId">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description" :id="descriptionId">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="sm:space-x-2">
                <Button ref="cancelButtonRef" variant="outline" @click="handleCancel">
                    {{ cancelLabel }}
                </Button>
                <Button
                    ref="confirmButtonRef"
                    :variant="confirmVariant"
                    :disabled="confirmDisabled"
                    @click="handleConfirm"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
