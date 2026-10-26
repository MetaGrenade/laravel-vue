<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Shown while the signed-in member's recovery codes are no longer a full set (see docs/two-factor.md). Making a new set is the way it goes away.
 */
const page = usePage<SharedData>();
const { t } = useI18n();

const show = computed(() => page.props.auth.replaceRecoveryCodes === true);
</script>

<template>
    <div v-if="show" role="status" class="border-b border-warning/40 bg-warning/15 text-foreground" data-testid="recovery-codes-prompt">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm">
            <div class="flex min-w-0 basis-full items-start gap-2 sm:flex-1 sm:basis-0 sm:items-center">
                <KeyRound class="mt-0.5 size-4 shrink-0 text-warning sm:mt-0" aria-hidden="true" />
                <p class="min-w-0 flex-1">{{ t('ui.security.recovery_prompt') }}</p>
            </div>
            <Button as-child size="sm" variant="outline" class="w-full sm:w-auto">
                <Link :href="route('security.edit')">{{ t('ui.security.recovery_prompt_action') }}</Link>
            </Button>
        </div>
    </div>
</template>
