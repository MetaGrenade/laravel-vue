<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { OrderDigital } from '@/types/dashboard';
import { Download, KeyRound } from '@lucide/vue';

defineProps<{ digital: OrderDigital }>();

const plural = (count: number, singular: string, plural: string) => (count === 1 ? singular : plural);
</script>

<template>
    <div v-if="digital.keys > 0 || digital.keys_waiting > 0 || digital.downloads" class="flex flex-wrap items-center gap-1.5">
        <Badge v-if="digital.keys > 0" variant="secondary" class="gap-1">
            <KeyRound class="size-3" aria-hidden="true" />
            {{ digital.keys }} {{ plural(digital.keys, 'licence key', 'licence keys') }}
        </Badge>
        <Badge v-if="digital.keys_waiting > 0" variant="highlight" class="gap-1">
            <KeyRound class="size-3" aria-hidden="true" />
            {{ digital.keys_waiting }} {{ plural(digital.keys_waiting, 'key', 'keys') }} on the way
        </Badge>
        <Badge v-if="digital.downloads" variant="secondary" class="gap-1">
            <Download class="size-3" aria-hidden="true" />
            Downloads
        </Badge>
    </div>
</template>
