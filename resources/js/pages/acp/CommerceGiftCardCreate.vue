<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Gift } from '@lucide/vue';

const props = defineProps<{ currency: string }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Gift cards', href: route('acp.commerce.gift-cards.index') },
    { title: 'Make a gift card', href: route('acp.commerce.gift-cards.create') },
];

const form = useForm({ amount: '', expires_at: '', note: '' });

const submit = () => form.post(route('acp.commerce.gift-cards.store'));

const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Make a gift card" />

        <AdminLayout>
            <div class="max-w-2xl space-y-6 pb-4">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Gift class="size-5" /> Make a gift card</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        You will be shown the card's code once, on the next page. It is not kept anywhere, so copy it then: if it is lost, the card
                        can be given a new code.
                    </p>
                </div>

                <form @submit.prevent="submit">
                    <Card>
                        <CardHeader>
                            <CardTitle>The card</CardTitle>
                            <CardDescription
                                >A card is in the shop's currency ({{ props.currency }}) and can pay for a whole order, shipping and tax
                                included.</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="space-y-5">
                            <div class="grid gap-2">
                                <Label for="gift-card-amount">Worth ({{ props.currency }})</Label>
                                <Input
                                    id="gift-card-amount"
                                    v-model="form.amount"
                                    type="number"
                                    inputmode="decimal"
                                    min="0.01"
                                    step="0.01"
                                    class="max-w-48 tabular-nums"
                                    :aria-invalid="!!form.errors.amount || undefined"
                                />
                                <InputError :message="form.errors.amount" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="gift-card-expires">Expires <span class="font-normal text-muted-foreground">(optional)</span></Label>
                                <Input id="gift-card-expires" v-model="form.expires_at" type="date" :min="tomorrow" class="max-w-48" />
                                <p class="text-xs text-muted-foreground">
                                    Leave it empty for a card that never expires. Some places limit how soon a gift card may expire.
                                </p>
                                <InputError :message="form.errors.expires_at" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="gift-card-note"
                                    >Note <span class="font-normal text-muted-foreground">(optional, for the team)</span></Label
                                >
                                <Textarea id="gift-card-note" v-model="form.note" rows="2" maxlength="500" placeholder="Who it is for, and why" />
                                <InputError :message="form.errors.note" />
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <Button type="submit" :disabled="form.processing || form.amount === ''">{{
                                    form.processing ? 'Making…' : 'Make the card'
                                }}</Button>
                                <Button variant="outline" as-child>
                                    <Link :href="route('acp.commerce.gift-cards.index')">Cancel</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
