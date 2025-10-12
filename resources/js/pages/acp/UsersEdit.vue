<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle } from 'lucide-vue-next';
import { Separator } from '@/components/ui/separator';

interface Role {
    id: number;
    name: string;
    guard_name?: string;
    description?: string | null;
}

interface UserRole {
    id: number;
    name: string;
}

interface UserSocialLink {
    label?: string | null;
    url?: string | null;
}

interface SocialAccount {
    id: number;
    provider: string;
    provider_id: string;
    name?: string | null;
    nickname?: string | null;
    email?: string | null;
    avatar?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
}

interface SocialProviderOption {
    key: string;
    label: string;
    description?: string | null;
}

interface User {
    id: number;
    nickname: string;
    email: string;
    email_verified_at: string | null;
    created_at?: string;
    updated_at?: string;
    last_activity_at?: string | null;
    roles: UserRole[];
    avatar_url?: string | null;
    profile_bio?: string | null;
    social_links?: UserSocialLink[] | null;
    social_accounts: SocialAccount[];
}

type UserForm = {
    nickname: string;
    email: string;
    roles: string[];
    avatar_url: string;
    profile_bio: string;
    social_links: Array<{ label: string; url: string }>;
};

const props = defineProps<{
    user: User;
    allRoles: Role[];
    availableSocialProviders: SocialProviderOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users ACP', href: '/acp/users' },
    { title: `Edit ${props.user.nickname}`, href: route('acp.users.edit', { user: props.user.id }) },
];

const { hasPermission } = usePermissions();
const canDeleteUsers = computed(() => hasPermission('users.acp.delete'));
const canVerifyUsers = computed(() => hasPermission('users.acp.verify'));

const form = useForm<UserForm>({
    nickname: props.user.nickname,
    email: props.user.email,
    roles: props.user.roles.map(role => role.name),
    avatar_url: props.user.avatar_url ?? '',
    profile_bio: props.user.profile_bio ?? '',
    social_links:
        props.user.social_links?.map((link) => ({
            label: typeof link?.label === 'string' ? link.label : '',
            url: typeof link?.url === 'string' ? link.url : '',
        })) ?? [],
});

const verifyForm = useForm({});
const deleteForm = useForm({});
const deleteDialogOpen = ref(false);
const deleteDialogTitle = computed(() => `Delete “${props.user.nickname}”?`);

const attachForm = useForm({
    provider: props.availableSocialProviders[0]?.key ?? '',
    provider_id: '',
    name: '',
    nickname: '',
    email: '',
    avatar: '',
});

const detachForm = useForm({});
const removingAccountId = ref<number | null>(null);

watch(
    () => props.availableSocialProviders,
    (providers) => {
        if (!providers.length) {
            attachForm.provider = '';
            return;
        }

        if (!providers.find(provider => provider.key === attachForm.provider)) {
            attachForm.provider = providers[0].key;
        }
    },
    { immediate: true }
);

const providerLookup = computed(() => {
    const entries = new Map<string, SocialProviderOption>();

    props.availableSocialProviders.forEach((option) => {
        entries.set(option.key, option);
    });

    return entries;
});

const providerLabel = (key: string) => providerLookup.value.get(key)?.label ?? key;

const attachAccount = () => {
    attachForm.post(route('acp.users.social-accounts.store', { user: props.user.id }), {
        preserveScroll: true,
        onSuccess: () => {
            attachForm.reset('provider_id', 'name', 'nickname', 'email', 'avatar');
        },
    });
};

const detachAccount = (accountId: number) => {
    removingAccountId.value = accountId;

    detachForm.delete(route('acp.users.social-accounts.destroy', {
        user: props.user.id,
        socialAccount: accountId,
    }), {
        preserveScroll: true,
        onFinish: () => {
            removingAccountId.value = null;
        },
    });
};

const { formatDate, fromNow } = useUserTimezone();

const handleSubmit = () => {
    form.put(route('acp.users.update', { user: props.user.id }), {
        preserveScroll: true,
    });
};

const toggleRole = (roleName: string, checked: boolean | string) => {
    const isChecked = checked === true || checked === 'indeterminate';

    if (isChecked) {
        if (!form.roles.includes(roleName)) {
            form.roles.push(roleName);
        }
    } else {
        form.roles = form.roles.filter(name => name !== roleName);
    }
};

const verifyUser = () => {
    verifyForm.put(route('acp.users.verify', { user: props.user.id }), {
        preserveScroll: true,
    });
};

const destroyUser = () => {
    deleteDialogOpen.value = true;
};

const cancelDestroyUser = () => {
    deleteDialogOpen.value = false;
};

const confirmDestroyUser = () => {
    if (deleteForm.processing) {
        return;
    }

    deleteDialogOpen.value = false;

    deleteForm.delete(route('acp.users.destroy', { user: props.user.id }), {
        preserveScroll: true,
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
        <Head :title="`Edit ${props.user.nickname}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6 w-full" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit user</h1>
                        <p class="text-sm text-muted-foreground">Update account details, manage their roles, or take further actions.</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.users.index')">Back to users</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <div class="flex flex-col gap-6">
                        <Card>
                            <CardHeader class="relative overflow-hidden">
                                <PlaceholderPattern class="absolute inset-0 opacity-10" />
                                <div class="relative space-y-1">
                                    <CardTitle>Account details</CardTitle>
                                    <CardDescription>Manage the basics of this user account.</CardDescription>
                                </div>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="nickname">Nickname</Label>
                                    <Input id="nickname" v-model="form.nickname" type="text" autocomplete="nickname" required />
                                    <InputError :message="form.errors.nickname" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="email">Email</Label>
                                    <Input id="email" v-model="form.email" type="email" autocomplete="email" required />
                                    <InputError :message="form.errors.email" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="avatar_url">Avatar URL</Label>
                                    <Input
                                        id="avatar_url"
                                        v-model="form.avatar_url"
                                        type="url"
                                        placeholder="https://example.com/avatar.png"
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Provide a direct link to an image that will represent the author across the blog.
                                    </p>
                                    <InputError :message="form.errors.avatar_url" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="profile_bio">Author bio</Label>
                                    <Textarea
                                        id="profile_bio"
                                        v-model="form.profile_bio"
                                        placeholder="Share a few sentences about this author’s background or expertise."
                                        class="min-h-28"
                                    />
                                    <InputError :message="form.errors.profile_bio" />
                                </div>

                                <div class="space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <Label class="text-sm font-medium">Social links</Label>
                                        <Button type="button" variant="outline" size="sm" @click="addSocialLink">
                                            Add social link
                                        </Button>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Highlight key destinations where readers can continue following this author.
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
                                                        placeholder="Twitter"
                                                    />
                                                    <InputError :message="form.errors[`social_links.${index}.label`]" />
                                                </div>
                                                <div class="grid gap-2">
                                                    <Label :for="`social-link-url-${index}`">URL</Label>
                                                    <Input
                                                        :id="`social-link-url-${index}`"
                                                        v-model="form.social_links[index].url"
                                                        type="url"
                                                        placeholder="https://social.example/@username"
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
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Roles</CardTitle>
                                <CardDescription>Assign the roles that define what this user can access.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div v-if="props.allRoles.length === 0" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                    No roles are currently defined. Create roles first in the Access Control panel.
                                </div>
                                <div v-else class="grid gap-3">
                                    <div
                                        v-for="role in props.allRoles"
                                        :key="role.id"
                                        class="flex items-start gap-3 rounded-md border p-3"
                                    >
                                        <Checkbox
                                            :id="`role-${role.id}`"
                                            :checked="form.roles.includes(role.name)"
                                            @update:checked="value => toggleRole(role.name, value)"
                                        />
                                        <div class="grid gap-1">
                                            <Label :for="`role-${role.id}`" class="font-medium leading-none">
                                                {{ role.name }}
                                            </Label>
                                            <p v-if="role.description" class="text-sm text-muted-foreground">
                                                {{ role.description }}
                                            </p>
                                            <p v-else class="text-xs text-muted-foreground">Guard: {{ role.guard_name ?? 'web' }}</p>
                                        </div>
                                    </div>
                                </div>
                                <InputError :message="form.errors.roles" />
                            </CardContent>
                        </Card>
                    </div>

                    <div class="flex flex-col gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Connected providers</CardTitle>
                                <CardDescription>Manage OAuth identities linked to this user.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div v-if="props.user.social_accounts.length" class="space-y-3">
                                    <div
                                        v-for="account in props.user.social_accounts"
                                        :key="account.id"
                                        class="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="space-y-1">
                                            <p class="font-medium leading-tight">{{ providerLabel(account.provider) }}</p>
                                            <p class="text-xs text-muted-foreground">
                                                Provider ID: {{ account.provider_id }}
                                            </p>
                                            <p v-if="account.nickname || account.name" class="text-sm text-muted-foreground">
                                                {{ account.nickname ?? account.name }}
                                            </p>
                                            <p v-if="account.email" class="text-sm text-muted-foreground">
                                                {{ account.email }}
                                            </p>
                                            <p v-if="account.created_at" class="text-xs text-muted-foreground">
                                                Linked {{ formatDate(account.created_at) }}
                                            </p>
                                        </div>

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            :disabled="detachForm.processing && removingAccountId === account.id"
                                            @click="detachAccount(account.id)"
                                        >
                                            <LoaderCircle
                                                v-if="detachForm.processing && removingAccountId === account.id"
                                                class="mr-2 h-4 w-4 animate-spin"
                                            />
                                            Disconnect
                                        </Button>
                                    </div>
                                </div>
                                <div v-else class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                                    No providers are currently linked to this user.
                                </div>

                                <Separator />

                                <form class="space-y-4" @submit.prevent="attachAccount">
                                    <div class="grid gap-3 sm:grid-cols-2 sm:gap-4">
                                        <div class="grid gap-2">
                                            <Label for="provider">Provider</Label>
                                            <select
                                                id="provider"
                                                v-model="attachForm.provider"
                                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <option v-for="provider in props.availableSocialProviders" :key="provider.key" :value="provider.key">
                                                    {{ provider.label }}
                                                </option>
                                            </select>
                                            <InputError :message="attachForm.errors.provider" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="provider_id">Provider identifier</Label>
                                            <Input id="provider_id" v-model="attachForm.provider_id" type="text" required />
                                            <InputError :message="attachForm.errors.provider_id" />
                                        </div>
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2 sm:gap-4">
                                        <div class="grid gap-2">
                                            <Label for="provider_name">Name</Label>
                                            <Input id="provider_name" v-model="attachForm.name" type="text" placeholder="Display name" />
                                            <InputError :message="attachForm.errors.name" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="provider_nickname">Nickname</Label>
                                            <Input id="provider_nickname" v-model="attachForm.nickname" type="text" placeholder="Username" />
                                            <InputError :message="attachForm.errors.nickname" />
                                        </div>
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2 sm:gap-4">
                                        <div class="grid gap-2">
                                            <Label for="provider_email">Email</Label>
                                            <Input id="provider_email" v-model="attachForm.email" type="email" placeholder="user@example.com" />
                                            <InputError :message="attachForm.errors.email" />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="provider_avatar">Avatar URL</Label>
                                            <Input id="provider_avatar" v-model="attachForm.avatar" type="url" placeholder="https://..." />
                                            <InputError :message="attachForm.errors.avatar" />
                                        </div>
                                    </div>

                                    <div class="flex justify-end">
                                        <Button type="submit" :disabled="attachForm.processing || !attachForm.provider">
                                            <LoaderCircle v-if="attachForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                                            Attach provider
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Account status</CardTitle>
                                <CardDescription>Reference information about this account.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm">
                                <div class="grid grid-cols-3 gap-2">
                                    <span class="text-muted-foreground">User ID</span>
                                    <span class="col-span-2 font-medium">#{{ props.user.id }}</span>
                                </div>
                                <div v-if="props.user.created_at" class="grid grid-cols-3 gap-2">
                                    <span class="text-muted-foreground">Created</span>
                                    <span class="col-span-2 font-medium">
                                        {{ formatDate(props.user.created_at) }}
                                        <span class="block text-xs text-muted-foreground">{{ fromNow(props.user.created_at) }}</span>
                                    </span>
                                </div>
                                <div v-if="props.user.updated_at" class="grid grid-cols-3 gap-2">
                                    <span class="text-muted-foreground">Updated</span>
                                    <span class="col-span-2 font-medium">
                                        {{ formatDate(props.user.updated_at) }}
                                        <span class="block text-xs text-muted-foreground">{{ fromNow(props.user.updated_at) }}</span>
                                    </span>
                                </div>
                                <div v-if="props.user.last_activity_at" class="grid grid-cols-3 gap-2">
                                    <span class="text-muted-foreground">Last active</span>
                                    <span class="col-span-2 font-medium">
                                        {{ formatDate(props.user.last_activity_at) }}
                                        <span class="block text-xs text-muted-foreground">{{ fromNow(props.user.last_activity_at) }}</span>
                                    </span>
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <span class="text-muted-foreground">Email</span>
                                    <span class="col-span-2 font-medium">
                                        <span v-if="props.user.email_verified_at" class="text-green-600 dark:text-green-500">Verified</span>
                                        <span v-else class="text-amber-600 dark:text-amber-500">Unverified</span>
                                    </span>
                                </div>
                            </CardContent>
                            <CardFooter v-if="!props.user.email_verified_at && canVerifyUsers" class="justify-end">
                                <Button
                                    variant="secondary"
                                    type="button"
                                    :disabled="verifyForm.processing"
                                    @click="verifyUser"
                                >
                                    Mark as verified
                                </Button>
                            </CardFooter>
                        </Card>

                        <Card v-if="canDeleteUsers">
                            <CardHeader>
                                <CardTitle>Danger zone</CardTitle>
                                <CardDescription>Permanently remove this account from the system.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <p class="text-sm text-muted-foreground">
                                    Deleting a user will revoke their access immediately and remove their data. This action cannot be undone.
                                </p>
                            </CardContent>
                            <CardFooter>
                                <Button
                                    variant="destructive"
                                    type="button"
                                    :disabled="deleteForm.processing"
                                    @click="destroyUser"
                                >
                                    Delete user
                                </Button>
                            </CardFooter>
                        </Card>
            </div>
        </div>
    </form>
            <ConfirmDialog
                v-model:open="deleteDialogOpen"
                :title="deleteDialogTitle"
                description="Deleting this user will permanently remove their account and associated data."
                confirm-label="Delete user"
                cancel-label="Cancel"
                :confirm-disabled="deleteForm.processing"
                @confirm="confirmDestroyUser"
                @cancel="cancelDestroyUser"
            />
        </AdminLayout>
    </AppLayout>
</template>
