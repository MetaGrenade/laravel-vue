<script setup lang="ts">
import type { Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

interface Props {
    href: string;
    tabindex?: number;
    method?: Method;
    as?: string;
}

defineProps<Props>();
</script>

<template>
    <Link :href="href" :tabindex="tabindex" :method="method" :as="as" class="font-medium text-primary underline-offset-4 hover:underline">
        <slot />
    </Link>
</template>
