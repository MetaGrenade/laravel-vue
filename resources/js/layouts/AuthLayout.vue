<script setup lang="ts">
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!-- The one page of a read-only view that uses this layout is the "verify your email" notice a customer who has not verified is sent to. -->
    <ImpersonationBanner />
    <AuthLayout :title="title" :description="description">
        <slot />
    </AuthLayout>
</template>
