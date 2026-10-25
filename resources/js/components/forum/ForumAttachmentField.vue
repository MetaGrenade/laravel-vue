<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

const files = defineModel<File[]>({ required: true });

defineProps<{
    id: string;
    error?: string;
}>();

const names = computed(() => files.value.map((file) => file.name).join(', '));

const onChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    files.value = input.files ? Array.from(input.files) : [];
};
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">Pictures and files</Label>
        <input
            :id="id"
            type="file"
            multiple
            accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,.zip"
            class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:bg-background file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-foreground"
            @change="onChange"
        />
        <p class="text-xs text-muted-foreground">Up to 5 files. A picture (JPEG, PNG, GIF or WebP), a PDF, plain text or a zip. 4 MB each.</p>
        <p v-if="names" class="text-sm">{{ names }}</p>
        <InputError :message="error" />
    </div>
</template>
