<script setup lang="ts">
import ApiScopePicker from '@/components/ApiScopePicker.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import UsageMeterCard from '@/components/UsageMeterCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import type { SharedData } from '@/types';
import type { ApiKey, ApiKeyExpiry, ApiKeyRotation, ApiScopeOption } from '@/types/api-keys';
import type { UsageSummary } from '@/types/usage';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeySquare, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    keys: ApiKey[];
    scopes: ApiScopeOption[];
    expiry: ApiKeyExpiry;
    rotation: ApiKeyRotation;
    limit: { active: number; max: number };
    max_calls: number;
    usage: UsageSummary;
}>();

const page = usePage<SharedData & { flash?: { plain_text_token?: string | null } }>();

const atTheLimit = computed(() => props.limit.active >= props.limit.max);

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden';

// --- Making a key -----------------------------------------------------------------------------------------------------------------

const dialogOpen = ref(false);

const defaultLifetime = () => (props.expiry.default !== null ? String(props.expiry.default) : 'never');

// A limit left empty is no limit. (`v-model.number` gives '' for an empty box.)
const form = useForm({
    name: '',
    scopes: [] as string[],
    expires_in: defaultLifetime(),
    hourly_quota: '' as number | '',
    daily_quota: '' as number | '',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

/** A scope that was refused comes back as `scopes.0`, `scopes.1`... Say the first. */
const scopeError = computed(() => errors.value.scopes ?? Object.entries(errors.value).find(([field]) => field.startsWith('scopes.'))?.[1]);

// Inertia's reset() goes back to the last *submitted* values, so the dialog would open as it was left: blank defaults are set explicitly.
const openNew = () => {
    form.defaults({ name: '', scopes: [], expires_in: defaultLifetime(), hourly_quota: '', daily_quota: '' }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};

const create = () => {
    form.post(route('settings.api-keys.store'), {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
};

const lifetimeLabel = (days: number) => (days === 365 ? '1 year' : `${days} days`);

// --- The secret, shown once ---------------------------------------------------------------------------------------------------------

const secret = ref('');
const secretOpen = ref(false);
const copied = ref(false);

// The server flashes the secret to the page that follows making (or rotating) a key. It is kept here only while the dialog is open.
watch(
    () => page.props.flash?.plain_text_token,
    (token) => {
        if (token) {
            secret.value = token;
            copied.value = false;
            secretOpen.value = true;
        }
    },
    { immediate: true },
);

watch(secretOpen, (open) => {
    if (!open) {
        secret.value = '';
    }
});

const copy = async () => {
    try {
        await navigator.clipboard.writeText(secret.value);
        copied.value = true;
    } catch {
        // No clipboard (or no permission): the text is selected when the box is focused, so it can still be copied by hand.
    }
};

const origin = computed(() => (typeof window === 'undefined' ? '' : window.location.origin));

// --- Rotating a key -----------------------------------------------------------------------------------------------------------------

const pendingRotate = ref<ApiKey | null>(null);
const rotateForm = useForm({ grace_hours: props.rotation.default });
const rotateErrors = computed(() => rotateForm.errors as Record<string, string | undefined>);

const graceLabel = (hours: number) => {
    if (hours === 0) {
        return 'Not at all: stop it at once';
    }

    if (hours % 24 === 0) {
        const days = hours / 24;

        return `${days} ${days === 1 ? 'day' : 'days'}`;
    }

    return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
};

const openRotate = (key: ApiKey) => {
    rotateForm.defaults({ grace_hours: props.rotation.default }).reset();
    rotateForm.clearErrors();
    pendingRotate.value = key;
};

const rotate = () => {
    if (!pendingRotate.value) {
        return;
    }

    rotateForm.post(route('settings.api-keys.rotate', pendingRotate.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            pendingRotate.value = null;
        },
    });
};

// --- Limiting a key -----------------------------------------------------------------------------------------------------------------

const pendingLimits = ref<ApiKey | null>(null);
const limitsForm = useForm({ hourly_quota: '' as number | '', daily_quota: '' as number | '' });
const limitsErrors = computed(() => limitsForm.errors as Record<string, string | undefined>);

const openLimits = (key: ApiKey) => {
    limitsForm.defaults({ hourly_quota: key.hourly_quota ?? '', daily_quota: key.daily_quota ?? '' }).reset();
    limitsForm.clearErrors();
    pendingLimits.value = key;
};

const saveLimits = () => {
    if (!pendingLimits.value) {
        return;
    }

    limitsForm.patch(route('settings.api-keys.limits', pendingLimits.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            pendingLimits.value = null;
        },
    });
};

// --- Taking a key away --------------------------------------------------------------------------------------------------------------

const pendingRevoke = ref<ApiKey | null>(null);
const pendingRemove = ref<ApiKey | null>(null);

const revoke = () => {
    if (!pendingRevoke.value) {
        return;
    }

    router.post(
        route('settings.api-keys.revoke', pendingRevoke.value.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                pendingRevoke.value = null;
            },
        },
    );
};

const remove = () => {
    if (!pendingRemove.value) {
        return;
    }

    router.delete(route('settings.api-keys.destroy', pendingRemove.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingRemove.value = null;
        },
    });
};

// --- Showing a key ------------------------------------------------------------------------------------------------------------------

const day = (value: string | null) => (value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : '');

const moment = (value: string | null) =>
    value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '';

const statusBadge = (key: ApiKey): { label: string; variant: 'secondary' | 'outline' | 'destructive' | 'highlight' } => {
    if (key.being_replaced) {
        return { label: 'Being replaced', variant: 'highlight' };
    }

    return {
        active: { label: 'Active', variant: 'secondary' },
        expired: { label: 'Expired', variant: 'outline' },
        revoked: { label: 'Revoked', variant: 'destructive' },
    }[key.status] as { label: string; variant: 'secondary' | 'outline' | 'destructive' };
};

const ending = (key: ApiKey) => {
    if (key.status === 'revoked') {
        return `Revoked ${day(key.revoked_at)}`;
    }

    if (key.being_replaced) {
        return `Replaced ${day(key.rotated_at)}; works until ${moment(key.expires_at)}`;
    }

    if (!key.expires_at) {
        return 'Never expires';
    }

    return `${key.status === 'expired' ? 'Expired' : 'Expires'} ${day(key.expires_at)}`;
};

const number = (value: number) => new Intl.NumberFormat().format(value);

const quotas = (key: ApiKey) =>
    [key.hourly_quota ? `${number(key.hourly_quota)} calls an hour` : null, key.daily_quota ? `${number(key.daily_quota)} a day` : null]
        .filter(Boolean)
        .join(' and ');
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'API keys', href: '/settings/api-keys' }]">
        <Head title="API keys" />

        <SettingsLayout>
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <HeadingSmall
                        title="API keys"
                        description="Let a script or another service use your account through the API. Each key is limited to what you choose, and stops working when it expires or you revoke it."
                    />
                    <Button :disabled="atTheLimit" :title="atTheLimit ? 'Revoke a key to make another' : undefined" @click="openNew">
                        <Plus class="size-4" /> Create key
                    </Button>
                </div>

                <p v-if="atTheLimit" class="text-sm text-muted-foreground" role="status">
                    You are using {{ limit.active }} of the {{ limit.max }} keys you can have at once. Revoke one you no longer need to make another.
                </p>

                <UsageMeterCard :meter="usage" />
                <p class="-mt-4 text-xs text-muted-foreground">Every call made with any of your keys counts here.</p>

                <div v-if="keys.length" class="space-y-4">
                    <Card v-for="key in keys" :key="key.id" :class="key.status !== 'active' && 'bg-muted/30'">
                        <CardContent class="space-y-3 pt-6">
                            <div class="flex items-start justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2 font-medium" :class="key.status !== 'active' && 'text-muted-foreground'">
                                    <KeySquare class="size-4 shrink-0 text-muted-foreground" />
                                    <span class="truncate">{{ key.name }}</span>
                                </span>
                                <Badge :variant="statusBadge(key).variant">{{ statusBadge(key).label }}</Badge>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge v-if="key.full_access" variant="highlight">Full access</Badge>
                                <Badge v-for="scope in key.scopes" :key="scope.key" variant="outline" :title="scope.key">{{ scope.label }}</Badge>
                                <span v-if="!key.full_access && !key.scopes.length" class="text-xs text-muted-foreground">
                                    No scopes: this key cannot do anything.
                                </span>
                            </div>

                            <dl class="grid gap-x-6 gap-y-1 text-xs text-muted-foreground sm:grid-cols-2">
                                <div class="flex gap-1.5">
                                    <dt>Created</dt>
                                    <dd class="text-foreground">{{ day(key.created_at) }}</dd>
                                </div>
                                <div class="flex gap-1.5">
                                    <dt>Last used</dt>
                                    <dd class="text-foreground">{{ key.last_used_at ? moment(key.last_used_at) : 'Never' }}</dd>
                                </div>
                                <div class="flex gap-1.5">
                                    <dt class="sr-only">Ends</dt>
                                    <dd class="text-foreground">{{ ending(key) }}</dd>
                                </div>
                                <div class="flex gap-1.5">
                                    <dt>This month</dt>
                                    <dd class="text-foreground">
                                        {{ number(key.calls_this_month) }} {{ key.calls_this_month === 1 ? 'call' : 'calls' }}
                                    </dd>
                                </div>
                            </dl>

                            <p v-if="quotas(key)" class="text-xs text-muted-foreground">
                                {{ key.limits_from_staff ? 'Limited by staff to' : 'Limited to' }} {{ quotas(key) }}.
                            </p>

                            <div class="flex flex-wrap gap-2">
                                <Button
                                    v-if="key.status === 'active' && !key.being_replaced"
                                    variant="outline"
                                    size="sm"
                                    :aria-label="`Rotate ${key.name}`"
                                    @click="openRotate(key)"
                                >
                                    Rotate
                                </Button>
                                <Button
                                    v-if="key.status === 'active' && !key.limits_from_staff"
                                    variant="outline"
                                    size="sm"
                                    :aria-label="`Set limits on ${key.name}`"
                                    @click="openLimits(key)"
                                >
                                    Limits
                                </Button>
                                <Button
                                    v-if="key.status === 'active'"
                                    variant="outline"
                                    size="sm"
                                    class="text-destructive"
                                    :aria-label="`Revoke ${key.name}`"
                                    @click="pendingRevoke = key"
                                >
                                    Revoke
                                </Button>
                                <Button v-else variant="ghost" size="sm" :aria-label="`Remove ${key.name}`" @click="pendingRemove = key"
                                    >Remove</Button
                                >
                            </div>
                        </CardContent>
                    </Card>
                </div>
                <p v-else class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                    You have no API keys yet. Create one to use the API from a script.
                </p>
            </div>

            <!-- Make a key -->
            <Dialog v-model:open="dialogOpen">
                <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Create an API key</DialogTitle>
                        <DialogDescription>
                            A key can do no more than you can, and only what you tick here. You will see the key itself once, straight after.
                        </DialogDescription>
                    </DialogHeader>

                    <form id="api-key-form" class="space-y-5" @submit.prevent="create">
                        <div class="grid gap-2">
                            <Label for="api-key-name">Name</Label>
                            <Input id="api-key-name" v-model="form.name" maxlength="100" placeholder="Deploy script" autocomplete="off" required />
                            <p class="text-xs text-muted-foreground">So you can tell your keys apart, and know which to revoke.</p>
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label>What it can do</Label>
                            <ApiScopePicker v-model="form.scopes" :scopes="scopes" />
                            <InputError :message="scopeError" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="api-key-expires">Expires</Label>
                            <select id="api-key-expires" v-model="form.expires_in" :class="selectClass">
                                <option v-for="days in expiry.days" :key="days" :value="String(days)">After {{ lifetimeLabel(days) }}</option>
                                <option v-if="expiry.never" value="never">Never</option>
                            </select>
                            <p v-if="form.expires_in === 'never'" class="text-xs text-muted-foreground">
                                A key that never expires works until you revoke it, so keep it somewhere safe.
                            </p>
                            <InputError :message="form.errors.expires_in" />
                        </div>

                        <fieldset class="grid gap-2">
                            <legend class="text-sm font-medium">
                                Limit its calls <span class="font-normal text-muted-foreground">(optional)</span>
                            </legend>
                            <p class="text-xs text-muted-foreground">
                                A ceiling on what this key can do, so a script gone wrong cannot use up your whole allowance. Leave a box empty for no
                                limit.
                            </p>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="grid gap-1.5">
                                    <Label for="api-key-hourly" class="text-xs font-normal">Calls an hour</Label>
                                    <Input
                                        id="api-key-hourly"
                                        v-model.number="form.hourly_quota"
                                        type="number"
                                        min="1"
                                        :max="max_calls"
                                        placeholder="No limit"
                                    />
                                    <InputError :message="errors.hourly_quota" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="api-key-daily" class="text-xs font-normal">Calls a day</Label>
                                    <Input
                                        id="api-key-daily"
                                        v-model.number="form.daily_quota"
                                        type="number"
                                        min="1"
                                        :max="max_calls"
                                        placeholder="No limit"
                                    />
                                    <InputError :message="errors.daily_quota" />
                                </div>
                            </div>
                        </fieldset>

                        <InputError :message="errors.limit" />
                        <InputError :message="errors.email" />
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Cancel</Button>
                        <Button type="submit" form="api-key-form" :disabled="form.processing">Create key</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Rotate a key -->
            <Dialog :open="pendingRotate !== null" @update:open="(open) => !open && (pendingRotate = null)">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Rotate {{ pendingRotate?.name }}?</DialogTitle>
                        <DialogDescription>
                            This makes a new key with the same name, scopes and limits, that lasts as long from today as this one was made to. You
                            will see the new key once, straight after. The old key can keep working for a while, so whatever uses it has time to be
                            given the new one.
                        </DialogDescription>
                    </DialogHeader>

                    <form id="api-key-rotate-form" class="space-y-4" @submit.prevent="rotate">
                        <div class="grid gap-2">
                            <Label for="api-key-grace">Keep the old key working for</Label>
                            <select id="api-key-grace" v-model="rotateForm.grace_hours" :class="selectClass">
                                <option v-for="hours in rotation.hours" :key="hours" :value="hours">{{ graceLabel(hours) }}</option>
                            </select>
                            <InputError :message="rotateErrors.grace_hours" />
                        </div>

                        <InputError :message="rotateErrors.limit" />
                        <InputError :message="rotateErrors.key" />
                        <InputError :message="rotateErrors.email" />
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="pendingRotate = null">Cancel</Button>
                        <Button type="submit" form="api-key-rotate-form" :disabled="rotateForm.processing">Rotate key</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Limit a key -->
            <Dialog :open="pendingLimits !== null" @update:open="(open) => !open && (pendingLimits = null)">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Limits for {{ pendingLimits?.name }}</DialogTitle>
                        <DialogDescription>
                            The most calls this key may make. Past it, the key is told to wait, and your account's allowance is not used. Leave a box
                            empty for no limit.
                        </DialogDescription>
                    </DialogHeader>

                    <form id="api-key-limits-form" class="space-y-4" @submit.prevent="saveLimits">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="grid gap-1.5">
                                <Label for="limits-hourly">Calls an hour</Label>
                                <Input
                                    id="limits-hourly"
                                    v-model.number="limitsForm.hourly_quota"
                                    type="number"
                                    min="1"
                                    :max="max_calls"
                                    placeholder="No limit"
                                />
                                <InputError :message="limitsErrors.hourly_quota" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="limits-daily">Calls a day</Label>
                                <Input
                                    id="limits-daily"
                                    v-model.number="limitsForm.daily_quota"
                                    type="number"
                                    min="1"
                                    :max="max_calls"
                                    placeholder="No limit"
                                />
                                <InputError :message="limitsErrors.daily_quota" />
                            </div>
                        </div>

                        <InputError :message="limitsErrors.limits" />
                    </form>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="pendingLimits = null">Cancel</Button>
                        <Button type="submit" form="api-key-limits-form" :disabled="limitsForm.processing">Save limits</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- The key, once -->
            <Dialog v-model:open="secretOpen">
                <DialogContent class="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Copy your new key now</DialogTitle>
                        <DialogDescription>It will not be shown again. If you lose it, revoke it and make another.</DialogDescription>
                    </DialogHeader>

                    <div class="space-y-3">
                        <div class="flex gap-2">
                            <Input
                                :model-value="secret"
                                readonly
                                aria-label="Your new API key"
                                class="font-mono text-xs"
                                @focus="($event.target as HTMLInputElement).select()"
                            />
                            <Button type="button" variant="outline" @click="copy">
                                <Check v-if="copied" class="size-4" />
                                <Copy v-else class="size-4" />
                                {{ copied ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">Send it as a bearer token:</p>
                        <pre class="overflow-x-auto rounded-md bg-muted p-3 text-xs">
curl {{ origin }}/api/user \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Accept: application/json"</pre>
                    </div>

                    <DialogFooter>
                        <Button type="button" @click="secretOpen = false">I have copied it</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                :open="pendingRevoke !== null"
                :title="`Revoke ${pendingRevoke?.name ?? 'this key'}?`"
                description="Anything using it stops working straight away. This cannot be undone: you would need to make a new key."
                confirm-label="Revoke key"
                @update:open="(open) => !open && (pendingRevoke = null)"
                @confirm="revoke"
                @cancel="pendingRevoke = null"
            />

            <ConfirmDialog
                :open="pendingRemove !== null"
                :title="`Remove ${pendingRemove?.name ?? 'this key'}?`"
                description="It is taken off this list, with the record of what it did. What your account used stays in your total for the month."
                confirm-label="Remove"
                @update:open="(open) => !open && (pendingRemove = null)"
                @confirm="remove"
                @cancel="pendingRemove = null"
            />
        </SettingsLayout>
    </AppLayout>
</template>
