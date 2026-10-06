<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type Appearance, useAppearance } from '@/composables/useAppearance';
import { useI18n } from '@/composables/useI18n';
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';

const { appearance, updateAppearance } = useAppearance();
const { t } = useI18n();

const options = computed(() => [
    { value: 'light', label: t('ui.theme.light'), icon: Sun },
    { value: 'dark', label: t('ui.theme.dark'), icon: Moon },
    { value: 'system', label: t('ui.theme.system'), icon: Monitor },
]);

const select = (value: unknown) => {
    if (value === 'light' || value === 'dark' || value === 'system') {
        updateAppearance(value as Appearance);
    }
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="text-muted-foreground hover:text-foreground" :aria-label="t('ui.theme.change')">
                <!-- Both icons render; CSS picks one so SSR and the client always agree. -->
                <Sun class="size-[1.15rem] dark:hidden" />
                <Moon class="hidden size-[1.15rem] dark:block" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-40">
            <DropdownMenuLabel class="text-xs font-medium text-muted-foreground">{{ t('ui.theme.label') }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuRadioGroup :model-value="appearance" @update:model-value="select">
                <DropdownMenuRadioItem v-for="option in options" :key="option.value" :value="option.value" class="gap-2">
                    <component :is="option.icon" class="size-4 text-muted-foreground" />
                    {{ option.label }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
