<script setup lang="ts">
import { computed, ref, toRefs, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown, LoaderCircle } from 'lucide-vue-next';

interface ActiveSession {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active_at: string;
    last_active_for_humans: string;
    is_current_device: boolean;
}

interface Props {
    sessions: ActiveSession[];
    twoFactorEnabled: boolean;
    twoFactorConfirmed: boolean;
    pendingSecret: string | null;
    qrCodeUrl: string | null;
    recoveryCodes: string[];
    status?: string | null;
    socialAccounts: Array<{
        id: number;
        provider: string;
        provider_id: string;
        name?: string | null;
        nickname?: string | null;
        email?: string | null;
        avatar?: string | null;
        linked_at?: string | null;
        updated_at?: string | null;
    }>;
    availableSocialProviders: Array<{
        key: string;
        label: string;
        description?: string | null;
    }>;
}

const props = defineProps<Props>();
const {
    sessions,
    twoFactorEnabled,
    twoFactorConfirmed,
    pendingSecret,
    qrCodeUrl,
    recoveryCodes,
    status,
    socialAccounts,
    availableSocialProviders,
} = toRefs(props);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Security settings',
        href: '/settings/security',
    },
];

const enableForm = useForm({});
const disableForm = useForm({});
const recoveryForm = useForm({});
const revokeForm = useForm({});
const confirmForm = useForm({
    code: '',
});

watch(
    pendingSecret,
    (value) => {
        if (!value) {
            confirmForm.reset();
        }
    }
);

const statusDetails = computed(() => {
    switch (status.value) {
        case 'session-revoked':
            return {
                title: 'Session revoked',
                description: 'The selected session has been signed out.',
                variant: 'default' as const,
            };
        case 'current-session-retained':
            return {
                title: 'Active session preserved',
                description: 'You cannot revoke the session currently in use.',
                variant: 'warning' as const,
            };
        case 'two-factor-secret-generated':
            return {
                title: 'Verification required',
                description:
                    'Scan the secret with your authenticator app and confirm using a 6-digit code to finish enrolling.',
                variant: 'default' as const,
            };
        case 'two-factor-confirmed':
            return {
                title: 'Multi-factor authentication enabled',
                description: 'Authenticator codes and recovery codes are now active for your account.',
                variant: 'default' as const,
            };
        case 'two-factor-disabled':
            return {
                title: 'Multi-factor authentication disabled',
                description: 'Authenticator codes and existing recovery codes have been cleared.',
                variant: 'warning' as const,
            };
        case 'recovery-codes-generated':
            return {
                title: 'Recovery codes refreshed',
                description: 'Store the new recovery codes in a safe location.',
                variant: 'default' as const,
            };
        case 'social-account-linked':
            return {
                title: 'Account connected',
                description: 'Your profile is now linked to the selected provider.',
                variant: 'default' as const,
            };
        case 'social-account-unlinked':
            return {
                title: 'Account disconnected',
                description: 'The provider has been removed from your account.',
                variant: 'warning' as const,
            };
        case 'social-account-conflict':
            return {
                title: 'Unable to connect account',
                description: 'That provider is already linked to a different account. Ask an administrator for assistance.',
                variant: 'destructive' as const,
            };
        case 'social-account-error':
            return {
                title: 'Authentication failed',
                description: 'We could not complete the sign-in with that provider. Please try again.',
                variant: 'destructive' as const,
            };
        case 'social-account-missing':
            return {
                title: 'Link not found',
                description: 'The selected provider was not linked to your account.',
                variant: 'warning' as const,
            };
        default:
            return null;
    }
});

const hasSessions = computed(() => sessions.value.length > 0);
const hasPendingSecret = computed(() => Boolean(pendingSecret.value));
const hasRecoveryCodes = computed(() => recoveryCodes.value && recoveryCodes.value.length > 0);
const linkedAccounts = computed(() => socialAccounts.value ?? []);
const providerMetadata = computed(() => availableSocialProviders.value ?? []);

const recoveryOpen = ref(false);

const revokeSession = (sessionId: string) => {
    revokeForm.delete(route('security.sessions.destroy', sessionId), {
        preserveScroll: true,
    });
};

const enableTwoFactor = () => {
    enableForm.post(route('security.mfa.store'), {
        preserveScroll: true,
    });
};

const confirmTwoFactor = () => {
    confirmForm.post(route('security.mfa.confirm'), {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset('code'),
    });
};

const disableTwoFactor = () => {
    disableForm.delete(route('security.mfa.destroy'), {
        preserveScroll: true,
    });
};

const regenerateRecoveryCodes = () => {
    recoveryForm.post(route('security.recovery-codes.store'), {
        preserveScroll: true,
    });
};

const unlinkForm = useForm({});
const unlinkingProvider = ref<string | null>(null);

const connectProvider = (provider: string) => {
    window.location.href = route('oauth.redirect', { provider });
};

const unlinkProvider = (provider: string) => {
    unlinkingProvider.value = provider;

    unlinkForm.delete(route('settings.social.unlink', { provider }), {
        preserveScroll: true,
        onFinish: () => {
            unlinkingProvider.value = null;
        },
    });
};

const providerAccount = (provider: string) =>
    linkedAccounts.value.find(account => account.provider === provider) ?? null;

watch(
    status,
    (value) => {
        if (value === 'two-factor-confirmed' || value === 'recovery-codes-generated') {
            recoveryOpen.value = true;
        }
    }
);

watch(hasRecoveryCodes, (value) => {
    if (!value) {
        recoveryOpen.value = false;
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Security settings" />

        <SettingsLayout>
            <div class="space-y-10">
                <div class="space-y-4">
                    <HeadingSmall
                        title="Active sessions"
                        description="Review and revoke devices currently authenticated with your account."
                    />

                    <Alert
                        v-if="statusDetails"
                        :variant="statusDetails.variant ?? 'default'"
                        class="border-l-4"
                        :class="
                            statusDetails.variant === 'destructive'
                                ? 'border-l-destructive'
                                : statusDetails.variant === 'warning'
                                  ? 'border-l-amber-500'
                                  : 'border-l-primary bg-muted/40'
                        "
                    >
                        <AlertTitle class="font-semibold">{{ statusDetails.title }}</AlertTitle>
                        <AlertDescription>{{ statusDetails.description }}</AlertDescription>
                    </Alert>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <Card v-for="session in sessions" :key="session.id">
                            <CardHeader>
                                <CardTitle class="flex flex-col space-y-1">
                                    <span>{{ session.ip_address ?? 'Unknown location' }}</span>
                                    <span
                                        v-if="session.is_current_device"
                                        class="text-sm font-normal text-primary"
                                    >
                                        Current device
                                    </span>
                                </CardTitle>
                                <CardDescription>
                                    Last active {{ session.last_active_for_humans }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-2">
                                <p class="text-sm text-muted-foreground">
                                    {{ session.user_agent ?? 'No user agent information recorded.' }}
                                </p>
                            </CardContent>
                            <CardFooter
                                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <span class="break-all text-xs text-muted-foreground">
                                    Session ID: {{ session.id }}
                                </span>
                                <Button
                                    v-if="!session.is_current_device"
                                    variant="outline"
                                    size="sm"
                                    :disabled="revokeForm.processing"
                                    @click="revokeSession(session.id)"
                                >
                                    Revoke
                                </Button>
                                <Button v-else variant="outline" size="sm" disabled>
                                    Active
                                </Button>
                            </CardFooter>
                        </Card>
                        <Card v-if="!hasSessions" class="lg:col-span-2">
                            <CardHeader>
                                <CardTitle>No active sessions</CardTitle>
                                <CardDescription>
                                    When you sign in on additional devices, they will appear in this list for quick review.
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    </div>
                </div>

                <div class="space-y-4">
                    <HeadingSmall
                        title="Connected accounts"
                        description="Link trusted providers for quicker sign-ins and community integrations."
                    />

                    <Card>
                        <CardContent class="space-y-4">
                            <div
                                v-for="provider in providerMetadata"
                                :key="provider.key"
                                class="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="space-y-2">
                                    <div>
                                        <p class="font-medium leading-tight">{{ provider.label }}</p>
                                        <p v-if="provider.description" class="text-sm text-muted-foreground">
                                            {{ provider.description }}
                                        </p>
                                    </div>

                                    <div v-if="providerAccount(provider.key)" class="text-sm text-muted-foreground">
                                        <p class="font-medium text-foreground">
                                            Linked as
                                            {{
                                                providerAccount(provider.key)?.nickname
                                                    ?? providerAccount(provider.key)?.name
                                                    ?? providerAccount(provider.key)?.email
                                                    ?? providerAccount(provider.key)?.provider_id
                                            }}
                                        </p>
                                        <p v-if="providerAccount(provider.key)?.email" class="text-xs text-muted-foreground">
                                            {{ providerAccount(provider.key)?.email }}
                                        </p>
                                        <p v-if="providerAccount(provider.key)?.linked_at" class="text-xs text-muted-foreground">
                                            Linked on {{ providerAccount(provider.key)?.linked_at }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <Button
                                        v-if="providerAccount(provider.key)"
                                        variant="outline"
                                        size="sm"
                                        :disabled="unlinkForm.processing && unlinkingProvider === provider.key"
                                        @click="unlinkProvider(provider.key)"
                                    >
                                        <LoaderCircle
                                            v-if="unlinkForm.processing && unlinkingProvider === provider.key"
                                            class="mr-2 h-4 w-4 animate-spin"
                                        />
                                        Disconnect
                                    </Button>
                                    <Button
                                        v-else
                                        variant="secondary"
                                        size="sm"
                                        @click="connectProvider(provider.key)"
                                    >
                                        Connect
                                    </Button>
                                </div>
                            </div>

                            <div v-if="providerMetadata.length === 0" class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                No social providers are available. Configure OAuth credentials in your environment and enable providers in the System Settings screen.
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Separator />

                <div class="space-y-6">
                    <HeadingSmall
                        title="Multi-factor authentication"
                        description="Add an extra layer of security using an authenticator app and recovery codes."
                    />

                    <div class="space-y-6">
                        <div v-if="!twoFactorEnabled">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Protect your account</CardTitle>
                                    <CardDescription>
                                        Multi-factor authentication requires both your password and a rotating code from an
                                        authenticator app to sign in.
                                    </CardDescription>
                                </CardHeader>
                                <CardFooter>
                                    <Button :disabled="enableForm.processing" @click="enableTwoFactor">
                                        Enable multi-factor authentication
                                    </Button>
                                </CardFooter>
                            </Card>
                        </div>

                        <div v-else>
                            <Card class="mb-4">
                                <CardHeader>
                                    <CardTitle>
                                        {{ twoFactorConfirmed ? 'Multi-factor authentication is enabled' : 'Finish setup' }}
                                    </CardTitle>
                                    <CardDescription>
                                        {{
                                            twoFactorConfirmed
                                                ? 'Your authenticator app codes are required during sign-in. Store the recovery codes safely.'
                                                : 'Scan the secret or enter it manually in your authenticator app, then confirm with a 6-digit code.'
                                        }}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent class="space-y-4">
                                    <div v-if="hasPendingSecret" class="space-y-3">
                                        <div class="space-y-1">
                                            <Label>Authenticator secret</Label>
                                            <Input :model-value="pendingSecret ?? ''" readonly />
                                            <p class="text-xs text-muted-foreground">
                                                Manually add this key to your authenticator app if you cannot scan a QR code.
                                            </p>
                                        </div>
                                        <div v-if="qrCodeUrl" class="space-y-1">
                                            <Label>otpauth URL</Label>
                                            <Input :model-value="qrCodeUrl" readonly />
                                            <p class="text-xs text-muted-foreground">
                                                Use this URL with any QR code generator to produce a scannable code for your authenticator.
                                            </p>
                                        </div>
                                        <form @submit.prevent="confirmTwoFactor" class="space-y-3">
                                            <div class="space-y-2">
                                                <Label for="code">Verification code</Label>
                                                <Input
                                                    id="code"
                                                    v-model="confirmForm.code"
                                                    type="text"
                                                    inputmode="numeric"
                                                    autocomplete="one-time-code"
                                                    placeholder="123456"
                                                />
                                                <InputError :message="confirmForm.errors.code" />
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <Button type="submit" :disabled="confirmForm.processing">
                                                    Confirm setup
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    :disabled="disableForm.processing"
                                                    @click="disableTwoFactor"
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        </form>
                                    </div>

                                    <div v-else class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            :disabled="disableForm.processing"
                                            @click="disableTwoFactor"
                                        >
                                            Disable multi-factor authentication
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            <Collapsible v-model:open="recoveryOpen">
                                <template #default="{ open }">
                                    <Card>
                                        <CardHeader class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="space-y-2">
                                                <CardTitle>Recovery codes</CardTitle>
                                                <CardDescription>
                                                    Use a recovery code if you lose access to your authenticator device.
                                                </CardDescription>
                                            </div>
                                            <CollapsibleTrigger as-child>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="w-full sm:w-auto"
                                                >
                                                    <span class="flex items-center justify-center gap-2">
                                                        {{ open ? 'Hide recovery codes' : 'View recovery codes' }}
                                                        <ChevronDown
                                                            class="h-4 w-4 transition-transform duration-200"
                                                            :class="open ? 'rotate-180' : ''"
                                                        />
                                                    </span>
                                                </Button>
                                            </CollapsibleTrigger>
                                        </CardHeader>
                                        <CollapsibleContent>
                                            <CardContent class="space-y-4">
                                                <div v-if="hasRecoveryCodes">
                                                    <p class="text-sm text-muted-foreground">
                                                        Each recovery code can be used once. Print or store them securely before leaving this page.
                                                    </p>
                                                    <ul class="grid gap-2 sm:grid-cols-2">
                                                        <li
                                                            v-for="code in recoveryCodes"
                                                            :key="code"
                                                            tabindex="0"
                                                            class="group relative cursor-pointer select-text rounded border border-dashed border-muted/60 bg-muted/40 px-3 py-2 font-mono text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                                        >
                                                            <span
                                                                class="block text-transparent transition duration-200 group-hover:text-foreground group-focus-visible:text-foreground selection:text-foreground"
                                                            >
                                                                {{ code }}
                                                            </span>
                                                            <span
                                                                aria-hidden="true"
                                                                class="pointer-events-none absolute inset-0 flex items-center justify-center text-xs font-medium text-muted-foreground transition-opacity duration-200 group-hover:opacity-0 group-focus-visible:opacity-0"
                                                            >
                                                                Hover or focus to reveal
                                                            </span>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <p v-else class="text-sm text-muted-foreground">
                                                    Recovery codes will appear after confirming multi-factor authentication.
                                                </p>
                                            </CardContent>
                                        </CollapsibleContent>
                                        <CardFooter class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                :disabled="recoveryForm.processing || !twoFactorConfirmed"
                                                @click="regenerateRecoveryCodes"
                                            >
                                                Generate new recovery codes
                                            </Button>
                                            <InputError :message="recoveryForm.errors.recovery" />
                                        </CardFooter>
                                    </Card>
                                </template>
                            </Collapsible>
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
