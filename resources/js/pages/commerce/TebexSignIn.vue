<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    /** The ways Tebex offers to sign in for this store. */
    options: { name: string; url: string }[];
    order: string;
    cancel: string;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Sign in to pay" />

        <div class="mx-auto max-w-lg space-y-6 py-8">
            <Card>
                <CardHeader>
                    <CardTitle>Sign in to pay</CardTitle>
                    <CardDescription>
                        This store needs to know which account to deliver to. Sign in with one of these and you will come straight back, then go on to
                        pay for order {{ order }}.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <Button v-for="option in options" :key="option.url" as-child size="lg" class="w-full" variant="outline">
                        <a :href="option.url" rel="noopener">Sign in with {{ option.name }}</a>
                    </Button>
                </CardContent>
            </Card>

            <p class="text-center text-sm text-muted-foreground">
                Changed your mind? <Link :href="cancel" class="underline">Back to your cart</Link>
            </p>
        </div>
    </AppLayout>
</template>
