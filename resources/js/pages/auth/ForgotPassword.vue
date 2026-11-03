<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';

defineProps<{
    status?: string;
}>();

const { t } = useI18n();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout :title="t('auth.forgot_password.title')" :description="t('auth.forgot_password.description')">
        <Head :title="t('auth.forgot_password.head')" />

        <div v-if="status" class="mb-4 rounded-md border border-success/30 bg-success/10 px-3 py-2 text-center text-sm font-medium text-success">
            {{ status }}
        </div>

        <div class="space-y-6">
            <form @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.common.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="off"
                        v-model="form.email"
                        autofocus
                        :placeholder="t('auth.common.email_placeholder')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button class="w-full" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ t('auth.forgot_password.submit') }}
                    </Button>
                </div>
            </form>

            <div class="text-center text-sm text-muted-foreground">
                <TextLink :href="route('login')">{{ t('auth.common.back_to_login') }}</TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
