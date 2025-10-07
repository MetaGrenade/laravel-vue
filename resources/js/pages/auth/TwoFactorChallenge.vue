<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { LoaderCircle } from 'lucide-vue-next';

const useRecovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const title = computed(() => (useRecovery.value ? 'Use a recovery code' : 'Enter your authentication code'));
const description = computed(() =>
    useRecovery.value
        ? 'Enter one of the recovery codes you saved when enabling multi-factor authentication.'
        : 'Open your authenticator app (Authy, Google Authenticator, etc.) and enter the 6-digit verification code.'
);

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
    <AuthBase title="Two-factor authentication" description="Complete the challenge to access your account">
        <Head title="Two-factor authentication" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-2">
                <h2 class="text-lg font-semibold">{{ title }}</h2>
                <p class="text-sm text-muted-foreground">{{ description }}</p>
            </div>

            <div v-if="!useRecovery" class="grid gap-2">
                <Label for="code">Authentication code</Label>
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
                <Label for="recovery_code">Recovery code</Label>
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
                    Continue
                </Button>

                <Button type="button" variant="link" class="w-full" @click="toggleMode">
                    <span v-if="useRecovery">Use an authenticator code instead</span>
                    <span v-else>Use a recovery code</span>
                </Button>
                <TextLink :href="route('login')" class="block text-center text-sm">Back to login</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
