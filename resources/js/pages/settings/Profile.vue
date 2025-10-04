<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AvatarUploader from '@/components/profile/AvatarUploader.vue';
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

const avatarPreview = ref(user.avatar_url ?? null);

const form = useForm({
    nickname: user.nickname,
    email: user.email,
    avatar: null as File | null,
    profile_bio: user.profile_bio ?? '',
    social_links: user.social_links ? user.social_links.map(link => ({ ...link })) : [],
    forum_signature: user.forum_signature ?? '',
});

const handleAvatarPreviewUpdate = (value: string | null) => {
    const currentUser = page.props.auth.user as User;
    avatarPreview.value = value ?? currentUser.avatar_url ?? null;
};

const submit = () => {
    form.transform(data => {
        const transformed: Record<string, unknown> = { ...data };

        if (data.avatar === null) {
            delete transformed.avatar;
        }

        return transformed;
    });

    form.patch(route('profile.update'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset('avatar');
            const currentUser = page.props.auth.user as User;
            avatarPreview.value = currentUser.avatar_url ?? null;
        },
        onFinish: () => {
            form.transform(data => data);
        },
    });
};

const addSocialLink = () => {
    form.social_links.push({ label: '', url: '' });
};

const removeSocialLink = (index: number) => {
    form.social_links.splice(index, 1);
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

                    <div class="space-y-3">
                        <Label class="text-sm font-medium">Avatar</Label>
                        <AvatarUploader
                            v-model="form.avatar"
                            :current-avatar="avatarPreview"
                            @update:preview="handleAvatarPreviewUpdate"
                        />
                        <InputError class="mt-2" :message="form.errors.avatar" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="profile_bio">Author bio</Label>
                        <Textarea
                            id="profile_bio"
                            v-model="form.profile_bio"
                            class="mt-1 block w-full"
                            rows="4"
                            placeholder="Share a few sentences about yourself for readers."
                        />
                        <p class="text-xs text-muted-foreground">
                            This bio may appear alongside your blog posts to introduce you to readers.
                        </p>
                        <InputError class="mt-2" :message="form.errors.profile_bio" />
                    </div>

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Label class="text-sm font-medium">Social links</Label>
                            <Button type="button" variant="outline" size="sm" @click="addSocialLink">
                                Add social link
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Highlight where readers can continue following your work (e.g., Mastodon, personal site).
                        </p>

                        <div v-if="form.social_links.length" class="space-y-3">
                            <div
                                v-for="(link, index) in form.social_links"
                                :key="`social-link-${index}`"
                                class="space-y-3 rounded-md border border-dashed p-3"
                            >
                                <div class="grid gap-3 sm:grid-cols-2 sm:gap-4">
                                    <div class="grid gap-2">
                                        <Label :for="`social-link-label-${index}`">Label</Label>
                                        <Input
                                            :id="`social-link-label-${index}`"
                                            v-model="form.social_links[index].label"
                                            type="text"
                                            placeholder="Mastodon"
                                        />
                                        <InputError :message="form.errors[`social_links.${index}.label`]" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`social-link-url-${index}`">URL</Label>
                                        <Input
                                            :id="`social-link-url-${index}`"
                                            v-model="form.social_links[index].url"
                                            type="url"
                                            placeholder="https://example.social/@username"
                                        />
                                        <InputError :message="form.errors[`social_links.${index}.url`]" />
                                    </div>
                                </div>
                                <div class="flex justify-end">
                                    <Button type="button" variant="ghost" size="sm" @click="removeSocialLink(index)">
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div v-else class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                            No social links added yet.
                        </div>
                        <InputError :message="form.errors.social_links" />
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
