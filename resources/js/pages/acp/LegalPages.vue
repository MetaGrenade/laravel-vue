<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    pages: {
        terms: string;
        privacy: string;
        cookies: string;
    };
}>();

const { t } = useI18n();
const { hasPermission } = usePermissions();
const canEdit = computed(() => hasPermission('system.acp.edit'));

const breadcrumbs = computed<BreadcrumbItem[]>(() => [{ title: t('legal.admin.title'), href: route('acp.legal.edit') }]);

const fields = [
    { key: 'terms', label: 'legal.pages.terms' },
    { key: 'privacy', label: 'legal.pages.privacy' },
    { key: 'cookies', label: 'legal.pages.cookies' },
] as const;

const form = useForm({
    terms: props.pages.terms,
    privacy: props.pages.privacy,
    cookies: props.pages.cookies,
});

const save = () => {
    if (!canEdit.value) {
        return;
    }

    form.put(route('acp.legal.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({
                terms: props.pages.terms,
                privacy: props.pages.privacy,
                cookies: props.pages.cookies,
            });
            form.reset();
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('legal.admin.title')" />

        <AdminLayout>
            <form class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4" @submit.prevent="save">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h1 class="text-lg font-semibold">{{ t('legal.admin.title') }}</h1>
                    <p class="mt-1 text-sm text-muted-foreground">{{ t('legal.admin.description') }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">{{ t('legal.admin.hint') }}</p>
                </div>

                <div v-for="field in fields" :key="field.key" class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="mb-3 flex items-baseline justify-between gap-3">
                        <Label :for="`legal-${field.key}`">{{ t(field.label) }}</Label>
                        <p class="text-xs text-muted-foreground">
                            {{ form[field.key].trim() === '' ? t('legal.admin.empty') : t('legal.admin.published') }}
                        </p>
                    </div>
                    <Textarea
                        :id="`legal-${field.key}`"
                        v-model="form[field.key]"
                        rows="12"
                        class="min-h-48 font-mono"
                        :disabled="!canEdit || form.processing"
                    />
                    <InputError class="mt-2" :message="form.errors[field.key]" />
                </div>

                <div>
                    <Button type="submit" :disabled="form.processing || !canEdit">{{ t('legal.admin.save') }}</Button>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
