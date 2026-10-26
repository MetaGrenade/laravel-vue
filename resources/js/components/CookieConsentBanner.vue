<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const { t } = useI18n();
const saving = ref(false);

const visible = computed(() => page.props.impersonation === null && page.props.legal.consent === null);
const showPolicy = computed(() => page.props.legal.pages.includes('cookies'));

const choose = (choice: 'necessary' | 'all') => {
    saving.value = true;

    router.post(
        route('legal.consent.store'),
        { choice },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <div v-if="visible" class="h-28" aria-hidden="true" />
    <div v-if="visible" class="fixed inset-x-0 bottom-0 z-40 border-t bg-background" role="region" aria-labelledby="cookie-consent-message">
        <div class="container-app flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
            <p id="cookie-consent-message" class="text-sm text-muted-foreground">
                {{ t('legal.banner.message') }}
                <Link
                    v-if="showPolicy"
                    :href="route('legal.show', { page: 'cookies' })"
                    class="font-medium text-foreground underline-offset-4 hover:underline"
                >
                    {{ t('legal.pages.cookies') }}
                </Link>
            </p>
            <div class="flex shrink-0 flex-wrap gap-2">
                <Button type="button" variant="outline" :disabled="saving" @click="choose('necessary')">
                    {{ t('legal.banner.necessary') }}
                </Button>
                <Button type="button" :disabled="saving" @click="choose('all')">
                    {{ t('legal.banner.all') }}
                </Button>
            </div>
        </div>
    </div>
</template>
