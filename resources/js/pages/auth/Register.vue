<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { Separator } from '@/components/ui/separator';

const form = useForm({
    nickname: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const socialProviders = [
    { key: 'google', label: 'Sign up with Google' },
    { key: 'discord', label: 'Sign up with Discord' },
    { key: 'steam', label: 'Sign up with Steam' },
];

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
    <AuthBase title="Create an account" description="Enter your details below to create your account">
        <Head title="Register" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Button
                        v-for="provider in socialProviders"
                        :key="provider.key"
                        type="button"
                        variant="outline"
                        class="w-full"
                        @click="redirectToProvider(provider.key)"
                    >
                        {{ provider.label }}
                    </Button>
                </div>

                <div class="flex items-center gap-3 text-xs uppercase tracking-wide text-muted-foreground">
                    <Separator class="flex-1" />
                    <span>Or continue with email</span>
                    <Separator class="flex-1" />
                </div>
            </div>

            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="nickname">Nickname</Label>
                    <Input id="nickname" type="text" required autofocus :tabindex="1" autocomplete="nickname" v-model="form.nickname" placeholder="Nickname" />
                    <InputError :message="form.errors.nickname" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input id="email" type="email" required :tabindex="2" autocomplete="email" v-model="form.email" placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        v-model="form.password"
                        placeholder="Password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        placeholder="Confirm password"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" tabindex="5" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Create account
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="route('login')" class="underline underline-offset-4" :tabindex="6">Log in</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
