<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Meter {
    key: string;
    label: string;
    unit: string;
}

interface Props {
    meters: Meter[];
    /** The meter being looked at (null when the site has none). */
    meter: string | null;
    /** The month, as 2026-10. */
    month: string;
    months: { value: string; label: string }[];
    retention_months: number;
    totals: { units: number; events: number; accounts: number };
    /** Units used on each day of the month, the empty days as 0. */
    daily: { date: string; units: number }[];
    top: { user: { id: number; nickname: string; email: string }; units: number; allowance: number | null; exhausted: boolean }[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Metered usage', href: route('acp.billing.usage.index') }];

const number = (value: number) => new Intl.NumberFormat().format(value);

const current = computed(() => props.meters.find((meter) => meter.key === props.meter) ?? null);

const choose = (changes: { meter?: string; month?: string }) => {
    router.get(
        route('acp.billing.usage.index'),
        { meter: props.meter, month: props.month, ...changes },
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const peak = computed(() => props.daily.reduce((most, day) => (day.units > most.units ? day : most), { date: '', units: 0 }));

/** A bar's height, 0 to 100% of the tallest day (a day with any use is at least a sliver). */
const height = (units: number) => (peak.value.units === 0 || units === 0 ? 0 : Math.max(3, Math.round((units / peak.value.units) * 100)));

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Metered usage" />

        <AdminLayout>
            <section class="flex w-full flex-col space-y-6">
                <HeadingSmall title="Metered usage" description="What accounts have used of each metered thing, month by month." />

                <div v-if="!current" class="rounded-lg border border-dashed border-border bg-card p-6 text-sm text-muted-foreground">
                    No meters are set up. They are listed under <code class="font-mono">usage.meters</code> in
                    <code class="font-mono">config/billing.php</code>.
                </div>

                <template v-else>
                    <div class="grid gap-4 rounded-lg border border-border bg-card p-4 shadow-xs sm:grid-cols-2">
                        <div v-if="props.meters.length > 1" class="space-y-2">
                            <Label for="usage-meter">Meter</Label>
                            <select
                                id="usage-meter"
                                :value="props.meter"
                                :class="selectClass"
                                @change="choose({ meter: ($event.target as HTMLSelectElement).value })"
                            >
                                <option v-for="meter in props.meters" :key="meter.key" :value="meter.key">{{ meter.label }}</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <Label for="usage-month">Month</Label>
                            <select
                                id="usage-month"
                                :value="props.month"
                                :class="selectClass"
                                @change="choose({ month: ($event.target as HTMLSelectElement).value })"
                            >
                                <option v-for="month in props.months" :key="month.value" :value="month.value">{{ month.label }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg border border-border bg-card p-4 shadow-xs">
                            <p class="text-xs text-muted-foreground">{{ current.label }} used</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number(props.totals.units) }}</p>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-xs">
                            <p class="text-xs text-muted-foreground">Times counted</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number(props.totals.events) }}</p>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-xs">
                            <p class="text-xs text-muted-foreground">Accounts</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ number(props.totals.accounts) }}</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-border bg-card p-4 shadow-xs">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-medium">Used each day</p>
                            <p v-if="peak.units > 0" class="text-xs text-muted-foreground">Busiest day: {{ peak.date }} ({{ number(peak.units) }})</p>
                        </div>
                        <div
                            class="mt-4 flex h-32 items-end gap-px"
                            role="img"
                            :aria-label="
                                peak.units > 0
                                    ? `${current.label} used each day; the busiest day was ${peak.date} with ${number(peak.units)}.`
                                    : `${current.label} was not used this month.`
                            "
                        >
                            <div
                                v-for="day in props.daily"
                                :key="day.date"
                                class="flex h-full flex-1 items-end"
                                :title="`${day.date}: ${number(day.units)}`"
                            >
                                <div class="w-full rounded-t-sm bg-primary/80" :style="{ height: `${height(day.units)}%` }" />
                            </div>
                        </div>
                        <div class="mt-1 flex justify-between text-xs text-muted-foreground">
                            <span>{{ props.daily[0]?.date }}</span>
                            <span>{{ props.daily[props.daily.length - 1]?.date }}</span>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <HeadingSmall title="Heaviest accounts" description="The accounts that used the most this month." />
                        <div class="overflow-x-auto rounded-lg border border-border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Account</TableHead>
                                        <TableHead class="text-right">Used</TableHead>
                                        <TableHead class="text-right">May use</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="props.top.length === 0">
                                        <TableCell colspan="4" class="text-center text-sm text-muted-foreground"
                                            >Nothing was used this month.</TableCell
                                        >
                                    </TableRow>
                                    <TableRow v-for="row in props.top" :key="row.user.id">
                                        <TableCell>
                                            <p class="font-medium">{{ row.user.nickname }}</p>
                                            <p class="text-xs text-muted-foreground">{{ row.user.email }}</p>
                                        </TableCell>
                                        <TableCell class="text-right tabular-nums">{{ number(row.units) }}</TableCell>
                                        <TableCell class="text-right tabular-nums">{{
                                            row.allowance === null ? '—' : number(row.allowance)
                                        }}</TableCell>
                                        <TableCell>
                                            <span
                                                v-if="row.exhausted"
                                                class="inline-flex items-center rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive"
                                            >
                                                At the limit
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            What an account may use is shown for the current month only. Events are kept for
                            {{ props.retention_months > 0 ? `${props.retention_months} months` : 'ever' }}.
                        </p>
                    </div>
                </template>
            </section>
        </AdminLayout>
    </AppLayout>
</template>
