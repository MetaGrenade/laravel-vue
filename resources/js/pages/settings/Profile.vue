<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

const form = useForm({
    nickname: user.nickname,
    email: user.email,
    avatar_url: user.avatar_url ?? '',
    forum_signature: user.forum_signature ?? '',
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Profile settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Profile information"
                    description="Update how you appear across the community."
                />

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="grid gap-2">
                        <Label for="nickname">Nickname</Label>
                        <Input id="nickname" class="mt-1 block w-full" v-model="form.nickname" required autocomplete="nickname" placeholder="Nickname / Display Name" />
                        <InputError class="mt-2" :message="form.errors.nickname" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="avatar_url">Avatar URL</Label>
                        <Input
                            id="avatar_url"
                            v-model="form.avatar_url"
                            type="url"
                            class="mt-1 block w-full"
                            autocomplete="off"
                            placeholder="https://example.com/avatar.png"
                        />
                        <p class="text-xs text-muted-foreground">
                            Provide a direct link to an image (PNG, JPG, or GIF) to use as your avatar.
                        </p>
                        <InputError class="mt-2" :message="form.errors.avatar_url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="forum_signature">Forum signature</Label>
                        <Textarea
                            id="forum_signature"
                            v-model="form.forum_signature"
                            class="mt-1 block w-full"
                            rows="4"
                            placeholder="Share a short sign-off, links, or pronouns."
                            maxlength="500"
                        />
                        <p class="text-xs text-muted-foreground">
                            This message appears beneath your forum posts. Markdown is not supported.
                        </p>
                        <InputError class="mt-2" :message="form.errors.forum_signature" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="route('verification.send')"
                                method="post"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:!decoration-current dark:decoration-neutral-500"
                            >
                                Click here to resend the verification email.
                            </Link>
                        </p>

                        <div v-if="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-green-600">
                            A new verification link has been sent to your email address.
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button :disabled="form.processing">Save</Button>

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-show="form.recentlySuccessful" class="text-sm text-neutral-600">Saved.</p>
                        </Transition>
                    </div>
                </form>
            </div>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
