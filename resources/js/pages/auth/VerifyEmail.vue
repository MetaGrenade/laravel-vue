<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout :title="t('auth.verify_email.title')" :description="t('auth.verify_email.description')">
        <Head :title="t('auth.verify_email.head')" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 rounded-md border border-success/30 bg-success/10 px-3 py-2 text-center text-sm font-medium text-success"
        >
            {{ t('auth.verify_email.link_sent') }}
        </div>

        <form @submit.prevent="submit" class="space-y-6 text-center">
            <Button :disabled="form.processing" variant="secondary">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                {{ t('auth.verify_email.resend') }}
            </Button>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm">{{ t('auth.verify_email.log_out') }}</TextLink>
        </form>
    </AuthLayout>
</template>
