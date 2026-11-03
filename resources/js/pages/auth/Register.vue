<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { LoaderCircle } from '@lucide/vue';
import { Separator } from '@/components/ui/separator';

const props = defineProps<{
    socialProviders?: Array<{ key: string; label: string; description?: string | null; enabled?: boolean }>;
}>();

const { t } = useI18n();

const form = useForm({
    nickname: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const socialProviders = computed(() => props.socialProviders ?? []);

const redirectToProvider = (provider: string) => {
    window.location.href = route('oauth.redirect', { provider });
};

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase :title="t('auth.register.title')" :description="t('auth.register.description')">
        <Head :title="t('auth.register.head')" />

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
                        {{ t('auth.register.sign_up_with', { provider: provider.label }) }}
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
                    <Label for="nickname">{{ t('auth.register.nickname') }}</Label>
                    <Input
                        id="nickname"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="nickname"
                        v-model="form.nickname"
                        :placeholder="t('auth.register.nickname')"
                    />
                    <InputError :message="form.errors.nickname" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.common.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        v-model="form.email"
                        :placeholder="t('auth.common.email_placeholder')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ t('auth.common.password') }}</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        v-model="form.password"
                        :placeholder="t('auth.common.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ t('auth.common.confirm_password') }}</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        :placeholder="t('auth.common.confirm_password')"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" tabindex="5" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ t('auth.register.submit') }}
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                {{ t('auth.register.have_account') }}
                <TextLink :href="route('login')" class="underline underline-offset-4" :tabindex="6">{{ t('auth.register.log_in') }}</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
