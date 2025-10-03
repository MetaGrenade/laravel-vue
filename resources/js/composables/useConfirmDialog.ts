import { computed, reactive } from 'vue';
import type { ButtonVariants } from '@/components/ui/button';

type ConfirmDialogOptions = {
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    confirmVariant?: ButtonVariants['variant'];
    confirmDisabled?: boolean;
    onConfirm: () => void;
    onCancel?: () => void;
};

const createInitialState = () => ({
    open: false,
    title: '',
    description: null as string | null,
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    confirmVariant: 'destructive' as ButtonVariants['variant'],
    confirmDisabled: false,
    onConfirm: null as null | (() => void),
    onCancel: null as null | (() => void),
});

export const useConfirmDialog = () => {
    const state = reactive(createInitialState());

    const description = computed(() => state.description ?? undefined);

    const openConfirmDialog = (options: ConfirmDialogOptions) => {
        state.title = options.title;
        state.description = options.description ?? null;
        state.confirmLabel = options.confirmLabel ?? 'Confirm';
        state.cancelLabel = options.cancelLabel ?? 'Cancel';
        state.confirmVariant = options.confirmVariant ?? 'destructive';
        state.confirmDisabled = options.confirmDisabled ?? false;
        state.onConfirm = options.onConfirm;
        state.onCancel = options.onCancel ?? null;
        state.open = true;
    };

    const closeConfirmDialog = () => {
        state.open = false;
        state.onConfirm = null;
        state.onCancel = null;
        state.confirmDisabled = false;
    };

    const handleConfirmDialogConfirm = () => {
        state.onConfirm?.();
        closeConfirmDialog();
    };

    const handleConfirmDialogCancel = () => {
        state.onCancel?.();
        closeConfirmDialog();
    };

    const setConfirmDialogConfirmDisabled = (value: boolean) => {
        state.confirmDisabled = value;
    };

    return {
        confirmDialogState: state,
        confirmDialogDescription: description,
        openConfirmDialog,
        closeConfirmDialog,
        handleConfirmDialogConfirm,
        handleConfirmDialogCancel,
        setConfirmDialogConfirmDisabled,
    };
};

export type { ConfirmDialogOptions };
