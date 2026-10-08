<script setup lang="ts">
import TaxonomyCard from '@/components/commerce/admin/TaxonomyCard.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Capabilities, TaxonomyEntry } from '@/types/catalogue';
import { Head } from '@inertiajs/vue3';
import { Tags } from '@lucide/vue';

defineProps<{
    brands: TaxonomyEntry[];
    categories: TaxonomyEntry[];
    tags: TaxonomyEntry[];
    can: Capabilities;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Brands, categories and tags', href: route('acp.commerce.taxonomy.index') },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Brands, categories and tags" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Tags class="size-5" /> Brands, categories and tags</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        The ways products are grouped. Customers can filter the shop by all three; you assign them on each product.
                    </p>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <TaxonomyCard
                        type="brands"
                        title="Brands"
                        noun="brand"
                        description="Who makes it. A product has one."
                        :entries="brands"
                        delete-note="Products keep existing; they just no longer have this brand."
                        :can="can"
                    />
                    <TaxonomyCard
                        type="categories"
                        title="Categories"
                        noun="category"
                        description="What kind of thing it is. A product can be in several."
                        :entries="categories"
                        delete-note="Products keep existing; they are just taken out of this category."
                        :can="can"
                    />
                    <TaxonomyCard
                        type="tags"
                        title="Tags"
                        noun="tag"
                        description="Looser labels such as Sale or New."
                        :entries="tags"
                        delete-note="Products keep existing; they just lose this tag."
                        :can="can"
                    />
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
