<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Switch } from '@/components/ui/switch';
import { useUserTimezone } from '@/composables/useUserTimezone';

interface PlanPayload {
    id: number;
    name: string;
    slug: string | null;
    stripe_price_id: string;
    interval: string;
    price: number;
    currency: string;
    description: string | null;
    features: string[];
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
    invoices_count: number;
}

interface Props {
    plan: PlanPayload;
    intervals: string[];
    default_currency: string;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing invoices', href: route('acp.billing.invoices.index') },
    { title: 'Subscription plans', href: route('acp.billing.plans.index') },
    { title: `Edit ${props.plan.name}`, href: route('acp.billing.plans.edit', { plan: props.plan.id }) },
];

const priceAsMajorUnits = (price: number): string => {
    return (price / 100).toFixed(2);
};

const form = useForm({
    name: props.plan.name,
    slug: props.plan.slug ?? '',
    stripe_price_id: props.plan.stripe_price_id,
    interval: props.plan.interval,
    price: priceAsMajorUnits(props.plan.price),
    currency: props.plan.currency ?? props.default_currency ?? 'USD',
    description: props.plan.description ?? '',
    features_text: props.plan.features.join('\n'),
    is_active: props.plan.is_active,
});

const featuresError = computed(() => form.errors.features ?? form.errors['features.0'] ?? null);
const { formatDate } = useUserTimezone();

const parsePrice = (value: string | number): number => {
    if (typeof value === 'number') {
        return value;
    }

    if (!value) {
        return 0;
    }

    const normalized = value.replace(',', '.');
    const parsed = Number.parseFloat(normalized);

    if (Number.isNaN(parsed)) {
        return 0;
    }

    return Math.round(parsed * 100);
};

const handleSubmit = () => {
    form.transform(data => {
        const features = data.features_text
            .split(/\r?\n/)
            .map(entry => entry.trim())
            .filter(entry => entry.length > 0);

        return {
            name: data.name,
            slug: data.slug || null,
            stripe_price_id: data.stripe_price_id,
            interval: data.interval,
            price: parsePrice(data.price),
            currency: (data.currency || props.default_currency || 'USD').toUpperCase(),
            description: data.description || null,
            features,
            is_active: data.is_active,
        };
    });

    form.put(route('acp.billing.plans.update', { plan: props.plan.id }), {
        preserveScroll: true,
        onFinish: () => {
            form.transform(data => ({ ...data }));
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit ${props.plan.name}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Edit subscription plan</h1>
                        <p class="text-sm text-muted-foreground">
                            Update plan metadata and availability without touching Stripe settings.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.billing.plans.index')">Back</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Update plan
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative space-y-1">
                            <CardTitle>Plan details</CardTitle>
                            <CardDescription>
                                Keep the plan aligned with Stripe pricing and communicate updates to members.
                            </CardDescription>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input id="name" v-model="form.name" type="text" autocomplete="off" required />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="slug">Slug</Label>
                            <Input
                                id="slug"
                                v-model="form.slug"
                                type="text"
                                autocomplete="off"
                                placeholder="Optional custom slug"
                            />
                            <InputError :message="form.errors.slug" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="stripe_price_id">Stripe price ID</Label>
                            <Input
                                id="stripe_price_id"
                                v-model="form.stripe_price_id"
                                type="text"
                                autocomplete="off"
                                required
                            />
                            <InputError :message="form.errors.stripe_price_id" />
                        </div>

                        <div class="grid gap-2 md:grid-cols-2 md:gap-6">
                            <div class="grid gap-2">
                                <Label for="price">Price</Label>
                                <Input
                                    id="price"
                                    v-model="form.price"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    required
                                />
                                <p class="text-xs text-muted-foreground">
                                    Enter the amount in your billing currency. We'll store the value in cents.
                                </p>
                                <InputError :message="form.errors.price" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="interval">Billing interval</Label>
                                <select
                                    id="interval"
                                    v-model="form.interval"
                                    class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option v-for="interval in props.intervals" :key="interval" :value="interval">
                                        {{ interval }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.interval" />
                            </div>
                        </div>

                        <div class="grid gap-2 md:grid-cols-2 md:gap-6">
                            <div class="grid gap-2">
                                <Label for="currency">Currency</Label>
                                <Input
                                    id="currency"
                                    v-model="form.currency"
                                    type="text"
                                    maxlength="3"
                                    class="uppercase"
                                    autocomplete="off"
                                    required
                                />
                                <InputError :message="form.errors.currency" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="description">Description</Label>
                                <Input
                                    id="description"
                                    v-model="form.description"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="Optional short summary"
                                />
                                <InputError :message="form.errors.description" />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="features">Plan features</Label>
                            <Textarea
                                id="features"
                                v-model="form.features_text"
                                placeholder="List each benefit on a new line"
                                class="min-h-32"
                            />
                            <InputError :message="featuresError" />
                        </div>

                        <div class="grid gap-2 md:grid-cols-2 md:gap-6">
                            <div class="grid gap-2">
                                <Label>Created</Label>
                                <p class="text-sm text-muted-foreground">
                                    {{ props.plan.created_at ? formatDate(props.plan.created_at, 'MMM D, YYYY h:mm A') : '—' }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label>Invoices linked</Label>
                                <p class="text-sm text-muted-foreground">
                                    {{ props.plan.invoices_count }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between rounded-lg border p-4">
                            <div>
                                <div class="font-medium">Active plan</div>
                                <p class="text-sm text-muted-foreground">
                                    Toggle off to hide the plan from member-facing billing screens.
                                </p>
                            </div>
                            <Switch v-model:checked="form.is_active" />
                        </div>
                        <InputError :message="form.errors.is_active" />
                    </CardContent>
                    <CardFooter class="flex items-center justify-end gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.billing.plans.index')">Back</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            Update plan
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
