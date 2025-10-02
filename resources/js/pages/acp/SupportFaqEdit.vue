<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { useUserTimezone } from '@/composables/useUserTimezone';

const props = defineProps<{
    faq: {
        id: number;
        question: string;
        answer: string;
        order: number;
        published: boolean;
        faq_category_id: number;
        created_at: string;
        updated_at: string;
    };
    categories: Array<{
        id: number;
        name: string;
        slug: string;
        description: string | null;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: `FAQ #${props.faq.id}`, href: route('acp.support.faqs.edit', { faq: props.faq.id }) },
];

const form = useForm({
    faq_category_id: props.faq.faq_category_id,
    question: props.faq.question,
    answer: props.faq.answer,
    order: props.faq.order,
    published: props.faq.published,
});

const { formatDate, fromNow } = useUserTimezone();

const createdAt = computed(() => formatDate(props.faq.created_at));
const updatedAt = computed(() => formatDate(props.faq.updated_at));

const handleSubmit = () => {
    form.put(route('acp.support.faqs.update', { faq: props.faq.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit FAQ #${props.faq.id}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Update FAQ #{{ props.faq.id }}</h1>
                        <p class="text-sm text-muted-foreground">
                            Refresh the answer content or adjust its visibility in the support centre.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.index')">Back to Support</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>FAQ content</CardTitle>
                                <CardDescription>
                                    Provide clear, concise instructions that resolve the question quickly.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="question">Question</Label>
                                <Input id="question" v-model="form.question" type="text" autocomplete="off" required />
                                <InputError :message="form.errors.question" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="answer">Answer</Label>
                                <Textarea id="answer" v-model="form.answer" class="min-h-48" required />
                                <InputError :message="form.errors.answer" />
                            </div>
                        </CardContent>
                    </Card>

                    <div class="grid gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Display settings</CardTitle>
                                <CardDescription>Control ordering and publish state for this entry.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="faq_category_id">Category</Label>
                                    <select
                                        id="faq_category_id"
                                        v-model.number="form.faq_category_id"
                                        class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                        :disabled="!props.categories.length"
                                        required
                                    >
                                        <option
                                            v-for="category in props.categories"
                                            :key="category.id"
                                            :value="category.id"
                                        >
                                            {{ category.name }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.faq_category_id" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="order">Display order</Label>
                                    <Input id="order" v-model.number="form.order" type="number" min="0" />
                                    <InputError :message="form.errors.order" />
                                </div>

                                <div class="flex items-center space-x-2">
                                    <Checkbox id="published" v-model:checked="form.published" />
                                    <Label for="published">Published</Label>
                                </div>
                                <InputError :message="form.errors.published" />
                            </CardContent>
                            <CardFooter class="justify-end">
                                <Button type="submit" :disabled="form.processing">Save changes</Button>
                            </CardFooter>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>History</CardTitle>
                                <CardDescription>Reference for when the FAQ was created and last touched.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm text-muted-foreground">
                                <div>
                                    <span class="font-medium text-foreground">Created</span>
                                    <p>{{ createdAt }} ({{ fromNow(props.faq.created_at) }})</p>
                                </div>
                                <div>
                                    <span class="font-medium text-foreground">Last updated</span>
                                    <p>{{ updatedAt }} ({{ fromNow(props.faq.updated_at) }})</p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
