<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { LoaderCircle } from '@lucide/vue';

const { t } = useI18n();

const useRecovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const title = computed(() => t(useRecovery.value ? 'auth.two_factor.recovery_title' : 'auth.two_factor.code_title'));
const description = computed(() => t(useRecovery.value ? 'auth.two_factor.recovery_description' : 'auth.two_factor.code_description'));

const submit = () => {
    if (useRecovery.value) {
        form.code = '';
    } else {
        form.recovery_code = '';
    }

    form.post(route('two-factor.login'), {
        preserveScroll: true,
    });
};

const toggleMode = () => {
    useRecovery.value = !useRecovery.value;
    form.clearErrors();
};
</script>

<template>
    <AuthBase :title="t('auth.two_factor.title')" :description="t('auth.two_factor.description')">
        <Head :title="t('auth.two_factor.head')" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <h2 class="text-lg font-semibold">{{ title }}</h2>
                <p class="text-sm text-muted-foreground">{{ description }}</p>
            </div>

            <div v-if="!useRecovery" class="grid gap-2">
                <Label for="code">{{ t('auth.two_factor.code_label') }}</Label>
                <Input
                    id="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    placeholder="123456"
                    maxlength="6"
                    v-model="form.code"
                    :disabled="form.processing"
                    autofocus
                />
                <InputError :message="form.errors.code" />
            </div>

            <div v-else class="grid gap-2">
                <Label for="recovery_code">{{ t('auth.two_factor.recovery_label') }}</Label>
                <Input
                    id="recovery_code"
                    autocomplete="one-time-code"
                    placeholder="ABCD-EFGH-IJKL"
                    v-model="form.recovery_code"
                    :disabled="form.processing"
                    autofocus
                />
                <InputError :message="form.errors.recovery_code" />
            </div>

            <div class="space-y-2">
                <Button type="submit" class="w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ t('auth.two_factor.submit') }}
                </Button>

                <Button type="button" variant="link" class="w-full" @click="toggleMode">
                    <span v-if="useRecovery">{{ t('auth.two_factor.use_code') }}</span>
                    <span v-else>{{ t('auth.two_factor.use_recovery') }}</span>
                </Button>
                <TextLink :href="route('login')" class="block text-center text-sm">{{ t('auth.common.back_to_login') }}</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
