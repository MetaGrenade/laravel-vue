<script setup lang="ts">
import { computed, toRefs, watch } from 'vue';
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
            };
        case 'current-session-retained':
            return {
                title: 'Active session preserved',
                description: 'You cannot revoke the session currently in use.',
            };
        case 'two-factor-secret-generated':
            return {
                title: 'Verification required',
                description:
                    'Scan the secret with your authenticator app and confirm using a 6-digit code to finish enrolling.',
            };
        case 'two-factor-confirmed':
            return {
                title: 'Multi-factor authentication enabled',
                description: 'Authenticator codes and recovery codes are now active for your account.',
            };
        case 'two-factor-disabled':
            return {
                title: 'Multi-factor authentication disabled',
                description: 'Authenticator codes and existing recovery codes have been cleared.',
            };
        case 'recovery-codes-generated':
            return {
                title: 'Recovery codes refreshed',
                description: 'Store the new recovery codes in a safe location.',
            };
        default:
            return null;
    }
});

const hasSessions = computed(() => sessions.value.length > 0);
const hasPendingSecret = computed(() => Boolean(pendingSecret.value));
const hasRecoveryCodes = computed(() => recoveryCodes.value && recoveryCodes.value.length > 0);

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

                    <Alert v-if="statusDetails" class="border-l-4 border-l-primary bg-muted/40">
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
                            <Card>
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
                                            <Input :value="pendingSecret ?? ''" readonly />
                                            <p class="text-xs text-muted-foreground">
                                                Manually add this key to your authenticator app if you cannot scan a QR code.
                                            </p>
                                        </div>
                                        <div v-if="qrCodeUrl" class="space-y-1">
                                            <Label>otpauth URL</Label>
                                            <Input :value="qrCodeUrl" readonly />
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

                            <Card>
                                <CardHeader>
                                    <CardTitle>Recovery codes</CardTitle>
                                    <CardDescription>
                                        Use a recovery code if you lose access to your authenticator device.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent class="space-y-4">
                                    <div v-if="hasRecoveryCodes">
                                        <p class="text-sm text-muted-foreground">
                                            Each recovery code can be used once. Print or store them securely before leaving this page.
                                        </p>
                                        <ul class="grid gap-2 sm:grid-cols-2">
                                            <li
                                                v-for="code in recoveryCodes"
                                                :key="code"
                                                class="rounded border bg-muted/40 px-3 py-2 font-mono text-sm"
                                            >
                                                {{ code }}
                                            </li>
                                        </ul>
                                    </div>
                                    <p v-else class="text-sm text-muted-foreground">
                                        Recovery codes will appear after confirming multi-factor authentication.
                                    </p>
                                </CardContent>
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
                        </div>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
