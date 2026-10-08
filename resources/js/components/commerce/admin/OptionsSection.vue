<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Capabilities, OptionRow, OptionValueRow } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    productId: number;
    options: OptionRow[];
    can: Capabilities;
}>();

// ----- Options ------------------------------------------------------------------------------------

const optionOpen = ref(false);
const editingOption = ref<OptionRow | null>(null);
const optionForm = useForm({ name: '', display_name: '', values: '' });

const openNewOption = () => {
    editingOption.value = null;
    optionForm.defaults({ name: '', display_name: '', values: '' }).reset();
    optionForm.clearErrors();
    optionOpen.value = true;
};

const openEditOption = (option: OptionRow) => {
    editingOption.value = option;
    optionForm.defaults({ name: option.name, display_name: option.display_name, values: '' }).reset();
    optionForm.clearErrors();
    optionOpen.value = true;
};

const saveOption = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            optionOpen.value = false;
        },
    };

    if (editingOption.value) {
        optionForm
            .transform(({ name, display_name }) => ({ name, display_name }))
            .put(route('acp.commerce.options.update', editingOption.value.id), options);
    } else {
        optionForm.transform((data) => data).post(route('acp.commerce.options.store', props.productId), options);
    }
};

// The list is checked value by value, so its errors are keyed values.0, values.1 and so on.
const valuesError = computed(() => Object.entries(optionForm.errors).find(([key]) => key.startsWith('values'))?.[1]);

const pendingOption = ref<OptionRow | null>(null);

const removeOption = () => {
    if (!pendingOption.value) {
        return;
    }

    router.delete(route('acp.commerce.options.destroy', pendingOption.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingOption.value = null;
        },
    });
};

// ----- Values -------------------------------------------------------------------------------------

const valueOpen = ref(false);
const valueOption = ref<OptionRow | null>(null);
const editingValue = ref<OptionValueRow | null>(null);
const valueForm = useForm({ value: '' });

const openNewValue = (option: OptionRow) => {
    valueOption.value = option;
    editingValue.value = null;
    valueForm.defaults({ value: '' }).reset();
    valueForm.clearErrors();
    valueOpen.value = true;
};

const openEditValue = (option: OptionRow, value: OptionValueRow) => {
    valueOption.value = option;
    editingValue.value = value;
    valueForm.defaults({ value: value.value }).reset();
    valueForm.clearErrors();
    valueOpen.value = true;
};

const saveValue = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            valueOpen.value = false;
        },
    };

    if (editingValue.value) {
        valueForm.put(route('acp.commerce.option-values.update', editingValue.value.id), options);
    } else if (valueOption.value) {
        valueForm.post(route('acp.commerce.option-values.store', valueOption.value.id), options);
    }
};

const pendingValue = ref<{ option: OptionRow; value: OptionValueRow } | null>(null);

const removeValue = () => {
    if (!pendingValue.value) {
        return;
    }

    router.delete(route('acp.commerce.option-values.destroy', pendingValue.value.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingValue.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-3">
        <div v-for="option in options" :key="option.id" class="rounded-lg border p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="font-medium">{{ option.display_name }}</span>
                    <span v-if="option.display_name !== option.name" class="ml-2 text-xs text-muted-foreground">({{ option.name }})</span>
                </div>
                <div class="flex gap-1">
                    <Button v-if="can.edit" variant="ghost" size="icon" :aria-label="`Rename ${option.display_name}`" @click="openEditOption(option)">
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="icon"
                        class="text-destructive"
                        :aria-label="`Delete ${option.display_name}`"
                        @click="pendingOption = option"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <Badge v-for="value in option.values" :key="value.id" variant="secondary" class="gap-1 pr-1">
                    {{ value.value }}
                    <button
                        v-if="can.edit"
                        type="button"
                        class="rounded-full p-0.5 hover:bg-background/60"
                        :aria-label="`Rename ${value.value}`"
                        @click="openEditValue(option, value)"
                    >
                        <Pencil class="size-3" />
                    </button>
                    <button
                        v-if="can.delete"
                        type="button"
                        class="rounded-full p-0.5 hover:bg-background/60"
                        :aria-label="`Delete ${value.value}`"
                        @click="pendingValue = { option, value }"
                    >
                        <X class="size-3" />
                    </button>
                </Badge>
                <span v-if="!option.values.length" class="text-sm text-muted-foreground">No values yet.</span>
                <Button v-if="can.create" variant="outline" size="sm" class="h-6 px-2 text-xs" @click="openNewValue(option)">
                    <Plus class="size-3" /> Value
                </Button>
            </div>
        </div>

        <p v-if="!options.length" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            No options. Add options such as Size or Colour if customers choose between versions of this product, then create a variant for each
            combination.
        </p>

        <Button v-if="can.create" variant="outline" size="sm" @click="openNewOption"><Plus class="size-4" /> Add an option</Button>

        <Dialog v-model:open="optionOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ editingOption ? 'Edit option' : 'Add an option' }}</DialogTitle>
                    <DialogDescription v-if="editingOption"> Variants made from this option are updated when you rename it. </DialogDescription>
                    <DialogDescription v-else>For example Size with the values S, M and L.</DialogDescription>
                </DialogHeader>

                <form id="option-form" class="space-y-5" @submit.prevent="saveOption">
                    <div class="grid gap-2">
                        <Label for="option-name">Name</Label>
                        <Input
                            id="option-name"
                            v-model="optionForm.name"
                            maxlength="120"
                            placeholder="Size"
                            :aria-invalid="!!optionForm.errors.name || undefined"
                        />
                        <InputError :message="optionForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="option-label">Label shown to customers <span class="text-muted-foreground">(optional)</span></Label>
                        <Input id="option-label" v-model="optionForm.display_name" maxlength="120" placeholder="Same as the name" />
                        <InputError :message="optionForm.errors.display_name" />
                    </div>

                    <div v-if="!editingOption" class="grid gap-2">
                        <Label for="option-values">Values <span class="text-muted-foreground">(optional)</span></Label>
                        <Textarea id="option-values" v-model="optionForm.values" rows="3" placeholder="S, M, L" />
                        <p class="text-xs text-muted-foreground">Separate them with commas or put each on its own line.</p>
                        <InputError :message="valuesError" />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="optionOpen = false">Cancel</Button>
                    <Button type="submit" form="option-form" :disabled="optionForm.processing">Save option</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="valueOpen">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{ editingValue ? 'Rename value' : `Add a value to ${valueOption?.display_name ?? 'the option'}` }}</DialogTitle>
                    <DialogDescription v-if="editingValue">Variants made from it are updated.</DialogDescription>
                </DialogHeader>

                <form id="value-form" class="space-y-3" @submit.prevent="saveValue">
                    <div class="grid gap-2">
                        <Label for="value-text">Value</Label>
                        <Input id="value-text" v-model="valueForm.value" maxlength="120" :aria-invalid="!!valueForm.errors.value || undefined" />
                        <InputError :message="valueForm.errors.value" />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="valueOpen = false">Cancel</Button>
                    <Button type="submit" form="value-form" :disabled="valueForm.processing">Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="pendingOption !== null"
            :title="`Delete ${pendingOption?.display_name ?? 'this option'}?`"
            description="Its values go with it. This is refused while a variant is made from it."
            confirm-label="Delete option"
            @update:open="(value) => !value && (pendingOption = null)"
            @confirm="removeOption"
            @cancel="pendingOption = null"
        />

        <ConfirmDialog
            :open="pendingValue !== null"
            :title="`Delete ${pendingValue?.value.value ?? 'this value'}?`"
            description="This is refused while a variant is made from it."
            confirm-label="Delete value"
            @update:open="(value) => !value && (pendingValue = null)"
            @confirm="removeValue"
            @cancel="pendingValue = null"
        />
    </div>
</template>
