<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { LoaderCircle } from '@lucide/vue';
import { Separator } from '@/components/ui/separator';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    socialProviders?: Array<{ key: string; label: string; description?: string | null; enabled?: boolean }>;
}>();

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const socialProviders = computed(() => props.socialProviders ?? []);

const redirectToProvider = (provider: string) => {
    window.location.href = route('oauth.redirect', { provider });
};

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase :title="t('auth.login.title')" :description="t('auth.login.description')">
        <Head :title="t('auth.login.head')" />

        <div v-if="status" class="mb-4 rounded-md border border-success/30 bg-success/10 px-3 py-2 text-center text-sm font-medium text-success">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div v-if="socialProviders.length" class="grid gap-4">
                <div class="grid gap-2">
                    <Button
                        v-for="provider in socialProviders"
                        :key="provider.key"
                        type="button"
                        variant="outline"
                        class="w-full"
                        @click="redirectToProvider(provider.key)"
                    >
                        {{ t('auth.login.continue_with', { provider: provider.label }) }}
                    </Button>
                </div>

                <div class="flex items-center gap-3 text-xs tracking-wide text-muted-foreground uppercase">
                    <Separator class="flex-1" />
                    <span>{{ t('auth.common.or_continue_with_email') }}</span>
                    <Separator class="flex-1" />
                </div>
            </div>

            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.common.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        v-model="form.email"
                        :placeholder="t('auth.common.email_placeholder')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{ t('auth.common.password') }}</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" :tabindex="5">
                            {{ t('auth.login.forgot_password') }}
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        v-model="form.password"
                        :placeholder="t('auth.common.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between" :tabindex="3">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model="form.remember" :tabindex="4" />
                        <span>{{ t('auth.login.remember_me') }}</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" :tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ t('auth.login.submit') }}
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                {{ t('auth.login.no_account') }}
                <TextLink :href="route('register')" :tabindex="5">{{ t('auth.login.sign_up') }}</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
