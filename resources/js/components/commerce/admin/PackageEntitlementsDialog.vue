<script setup lang="ts">
import EntitlementPickerDialog from '@/components/commerce/admin/EntitlementPickerDialog.vue';
import type { EntitlementChoice } from '@/types/entitlements';
import type { TebexPackageRow } from '@/types/tebex';

defineProps<{
    /** The package being edited; the dialog is open while there is one. */
    package: TebexPackageRow | null;
    /** Everything a package can be set to give. */
    choices: EntitlementChoice[];
}>();

defineEmits<{ close: [] }>();
</script>

<template>
    <EntitlementPickerDialog
        :open="package !== null"
        :title="`What ${package?.name} gives`"
        description="What the buyer's account in the shop is given when they pay for it. It applies to purchases made from now on; orders already paid keep what they were given."
        :given="package?.entitlements ?? []"
        :choices="choices"
        :url="package ? route('acp.commerce.tebex.packages.entitlements.update', package.id) : null"
        amount-before="adds"
        amount-after="per unit"
        :amount-label="(choice) => `How much one unit of the package adds to ${choice.label}`"
        @close="$emit('close')"
    />
</template>
