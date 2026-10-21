<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import type { TebexPackageRow, TebexRecurringCounts, TebexStoreInfo, TebexSyncRun, TebexTestResult, TebexWebhookInfo } from '@/types/tebex';
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, RefreshCw, Store } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps<{
    connection: { configured: boolean; token: string | null; has_private_key: boolean };
    store: TebexStoreInfo | null;
    test: TebexTestResult | null;
    webhook: TebexWebhookInfo;
    recurring: TebexRecurringCounts;
    sync: { latest: TebexSyncRun | null; last_ok: TebexSyncRun | null; every_minutes: number; runs: TebexSyncRun[] };
    counts: { categories: number; packages: number; subscriptions: number; removed: number };
    categories: { id: number; name: string }[];
    packages: { data: TebexPackageRow[]; meta?: PaginationMeta | null };
    filters: { search: string; status: 'listed' | 'removed' | 'all'; category: number | null };
    shop_currency: string;
    currencies: string[];
    can: { edit: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Tebex', href: route('acp.commerce.tebex.index') },
];

const busy = ref<'test' | 'sync' | null>(null);

const post = (action: 'test' | 'sync') => {
    busy.value = action;

    router.post(route(`acp.commerce.tebex.${action}`), {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
};

const rows = computed(() => props.packages.data ?? []);

const tabs: { value: 'listed' | 'removed' | 'all'; label: string }[] = [
    { value: 'listed', label: 'Listed' },
    { value: 'removed', label: 'No longer listed' },
    { value: 'all', label: 'All' },
];

const filterState = reactive({ search: props.filters.search });

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters.search;
    },
    { deep: true },
);

const go = (overrides: Record<string, string | number | null> = {}) => {
    const query = Object.fromEntries(
        Object.entries({ status: props.filters.status, category: props.filters.category, search: filterState.search, ...overrides }).filter(
            ([key, value]) => value !== null && String(value).trim() !== '' && !(key === 'status' && value === 'listed'),
        ),
    );

    router.get(route('acp.commerce.tebex.index'), query, { preserveScroll: true, preserveState: true, replace: true });
};

const { meta, page, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.packages.meta ?? null),
    itemsLength: computed(() => rows.value.length),
    defaultPerPage: 25,
    itemLabel: 'package',
    itemLabelPlural: 'packages',
    onNavigate: (newPage) => go({ page: newPage }),
});

const when = (iso: string | null) =>
    iso ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '';

const statusLabel = { running: 'Running', ok: 'Succeeded', failed: 'Failed' } as const;
const statusVariant = { running: 'outline', ok: 'secondary', failed: 'destructive' } as const;
const triggerLabel = { schedule: 'Scheduled', command: 'Command', admin: 'Staff' } as const;

const summary = (run: TebexSyncRun) => {
    const packages = run.counts?.packages;

    return packages ? `${packages.created} added, ${packages.updated} changed, ${packages.removed} no longer listed` : '';
};

const otherCurrency = computed(() => props.currencies.some((currency) => currency !== props.shop_currency));
const selectClass = 'rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-primary focus:outline-hidden';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tebex" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Store class="size-5" /> Tebex</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Tebex keeps the catalogue and takes the payment. The shop reads a copy of the store's categories and packages, refreshed from
                        here, by <code class="rounded bg-muted px-1 py-0.5 text-xs">php artisan tebex:sync</code> and on a schedule. Packages and
                        prices are changed in the Tebex panel and arrive with the next refresh.
                    </p>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                Connection
                                <Badge :variant="connection.configured ? 'secondary' : 'outline'">{{
                                    connection.configured ? 'Set up' : 'Not set up'
                                }}</Badge>
                            </CardTitle>
                            <CardDescription>The keys come from the environment and are never shown in full.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                                <dt class="text-muted-foreground">Public token</dt>
                                <dd class="font-mono">{{ connection.token ?? 'Not set (TEBEX_PUBLIC_TOKEN)' }}</dd>
                                <dt class="text-muted-foreground">Private key</dt>
                                <dd>{{ connection.has_private_key ? 'Set' : 'Not set (TEBEX_PRIVATE_KEY): not needed to read the catalogue' }}</dd>
                            </dl>

                            <Button v-if="can.edit && connection.configured" variant="outline" :disabled="busy !== null" @click="post('test')">
                                <CircleCheck class="size-4" /> {{ busy === 'test' ? 'Asking Tebex…' : 'Test connection' }}
                            </Button>

                            <Alert v-if="test && test.ok">
                                <CircleCheck class="size-4" />
                                <AlertTitle>Connected to {{ test.name }}</AlertTitle>
                                <AlertDescription>
                                    The store sells in {{ test.currency
                                    }}<template v-if="test.platform"> and is set up for {{ test.platform }}</template
                                    >.
                                    <template v-if="test.disabled"> Tebex says the store is switched off, so customers cannot pay there.</template>
                                </AlertDescription>
                            </Alert>
                            <p v-if="props.store && props.store.supported && props.store.platform" class="text-sm text-muted-foreground">
                                Set up for {{ props.store.platform }}:
                                <template v-if="props.store.identity === 'username'"
                                    >buyers type their {{ props.store.username_label }} at checkout.</template
                                >
                                <template v-else-if="props.store.identity === 'sign-in'">buyers sign in through Tebex before they pay.</template>
                                <template v-else>buyers are asked for nothing but their email.</template>
                            </p>

                            <Alert v-if="props.store && !props.store.supported" variant="destructive">
                                <CircleAlert class="size-4" />
                                <AlertTitle>The shop does not know this kind of store yet</AlertTitle>
                                <AlertDescription>{{ props.store.reason }}</AlertDescription>
                            </Alert>
                            <Alert v-if="test && !test.ok" variant="destructive">
                                <CircleAlert class="size-4" />
                                <AlertTitle>Could not connect</AlertTitle>
                                <AlertDescription>{{ test.message }}</AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Catalogue</CardTitle>
                            <CardDescription>
                                <template v-if="sync.every_minutes > 0"
                                    >Refreshed every {{ sync.every_minutes }} minutes while the scheduler runs.</template
                                >
                                <template v-else
                                    >Not refreshed on a schedule (TEBEX_SYNC_EVERY_MINUTES is 0); refresh it by hand or with the command.</template
                                >
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <dl class="grid grid-cols-3 gap-4 text-sm">
                                <div>
                                    <dt class="text-muted-foreground">Categories</dt>
                                    <dd class="text-2xl font-semibold tabular-nums">{{ counts.categories }}</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">Packages</dt>
                                    <dd class="text-2xl font-semibold tabular-nums">{{ counts.packages }}</dd>
                                    <dd v-if="counts.subscriptions" class="text-xs text-muted-foreground">{{ counts.subscriptions }} recurring</dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">No longer listed</dt>
                                    <dd class="text-2xl font-semibold tabular-nums">{{ counts.removed }}</dd>
                                </div>
                            </dl>

                            <p v-if="sync.latest" class="text-sm">
                                <span class="text-muted-foreground">Last refresh: </span>
                                <Badge :variant="statusVariant[sync.latest.status]">{{ statusLabel[sync.latest.status] }}</Badge>
                                {{ when(sync.latest.started_at) }}
                            </p>
                            <p v-else class="text-sm text-muted-foreground">Not refreshed yet.</p>

                            <Alert v-if="sync.latest?.status === 'failed'" variant="destructive">
                                <CircleAlert class="size-4" />
                                <AlertTitle>The last refresh failed</AlertTitle>
                                <AlertDescription>
                                    {{ sync.latest.error }}
                                    <template v-if="sync.last_ok"> The catalogue below is from {{ when(sync.last_ok.started_at) }}.</template>
                                </AlertDescription>
                            </Alert>

                            <Alert v-if="otherCurrency">
                                <CircleAlert class="size-4" />
                                <AlertTitle>Prices are not in the shop's currency</AlertTitle>
                                <AlertDescription>
                                    The shop sells in {{ shop_currency }}; the Tebex catalogue has prices in {{ currencies.join(', ') }}. Packages are
                                    shown, and will be sold, in their own currency.
                                </AlertDescription>
                            </Alert>

                            <Button v-if="can.edit && connection.configured" :disabled="busy !== null" @click="post('sync')">
                                <RefreshCw class="size-4" :class="{ 'animate-spin': busy === 'sync' }" />
                                {{ busy === 'sync' ? 'Refreshing…' : 'Refresh catalogue' }}
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            Payments
                            <Badge :variant="webhook.secret_configured ? 'secondary' : 'outline'">{{
                                webhook.secret_configured ? 'Ready' : 'Not set up'
                            }}</Badge>
                        </CardTitle>
                        <CardDescription>
                            Tebex tells the shop a payment happened by webhook. Without it an order paid at Tebex is never marked paid, so Tebex is
                            not offered as ready until the secret is set.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                            <dt class="text-muted-foreground">Endpoint</dt>
                            <dd class="font-mono break-all">{{ webhook.url }}</dd>
                            <dt class="text-muted-foreground">Secret</dt>
                            <dd>{{ webhook.secret_configured ? 'Set' : 'Not set (TEBEX_WEBHOOK_SECRET)' }}</dd>
                            <dt class="text-muted-foreground">Sender check</dt>
                            <dd>
                                {{
                                    webhook.ip_check
                                        ? "Only Tebex's two addresses are answered"
                                        : 'Off (TEBEX_WEBHOOK_CHECK_IP): only the signature is checked'
                                }}
                            </dd>
                            <dt class="text-muted-foreground">Last delivery</dt>
                            <dd>{{ webhook.last_received_at ? when(webhook.last_received_at) : 'Nothing received yet' }}</dd>
                            <dt class="text-muted-foreground">Recurring payments</dt>
                            <dd>
                                {{ recurring.running }} running<template v-if="recurring.overdue">, {{ recurring.overdue }} overdue</template
                                ><template v-if="recurring.ending">, {{ recurring.ending }} ending</template>, {{ recurring.ended }} over
                            </dd>
                        </dl>

                        <ol
                            v-if="!webhook.secret_configured || !webhook.last_received_at"
                            class="list-decimal space-y-1 pl-5 text-sm text-muted-foreground"
                        >
                            <li>
                                In the Tebex panel open Developers → Webhooks → Endpoints and add the endpoint above, with the payment, dispute and
                                recurring payment events.
                            </li>
                            <li>
                                Copy the secret Tebex shows into <code class="rounded bg-muted px-1 py-0.5 text-xs">TEBEX_WEBHOOK_SECRET</code> and
                                reload the configuration.
                            </li>
                            <li>Press Validate next to the endpoint: Tebex sends nothing else until it is validated.</li>
                        </ol>

                        <Alert v-if="webhook.needs_a_look > 0">
                            <CircleAlert class="size-4" />
                            <AlertTitle
                                >{{ webhook.needs_a_look }} {{ webhook.needs_a_look === 1 ? 'delivery needs' : 'deliveries need' }} a look</AlertTitle
                            >
                            <AlertDescription>
                                A payment that matched no order, one that did not add up, or a delivery that failed and is waiting to be sent again.
                                <Link
                                    v-if="webhook.can_view_log"
                                    :href="route('acp.billing.webhooks.index', { provider: 'tebex', processed: 'attention' })"
                                    class="font-medium underline"
                                    >See them</Link
                                >
                            </AlertDescription>
                        </Alert>

                        <Button v-else-if="webhook.can_view_log" variant="outline" as-child>
                            <Link :href="route('acp.billing.webhooks.index', { provider: 'tebex' })">View deliveries</Link>
                        </Button>
                    </CardContent>
                </Card>

                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Package status">
                    <Button
                        v-for="tab in tabs"
                        :key="tab.value"
                        size="sm"
                        :variant="filters.status === tab.value ? 'default' : 'outline'"
                        role="tab"
                        :aria-selected="filters.status === tab.value"
                        @click="go({ status: tab.value, page: 1 })"
                    >
                        {{ tab.label }}
                    </Button>
                </div>

                <form class="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4 shadow-xs" @submit.prevent="go({ page: 1 })">
                    <div class="flex min-w-60 flex-1 flex-col gap-2">
                        <label for="tebex-search" class="text-sm font-medium">Search</label>
                        <Input id="tebex-search" v-model="filterState.search" type="search" placeholder="Name or identifier" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="tebex-category" class="text-sm font-medium">Category</label>
                        <select
                            id="tebex-category"
                            :value="filters.category ?? ''"
                            :class="selectClass"
                            @change="go({ category: ($event.target as HTMLSelectElement).value, page: 1 })"
                        >
                            <option value="">All categories</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </div>
                    <Button type="submit">Search</Button>
                    <Button
                        v-if="filterState.search"
                        type="button"
                        variant="outline"
                        @click="
                            filterState.search = '';
                            go({ page: 1, search: '' });
                        "
                        >Clear</Button
                    >
                </form>

                <Card>
                    <CardContent class="overflow-x-auto pt-6">
                        <Table v-if="rows.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Package</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead class="text-right">Price</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="row in rows" :key="row.id">
                                    <TableCell>
                                        <p class="font-medium">{{ row.name }}</p>
                                        <p class="font-mono text-xs text-muted-foreground">{{ row.ident ?? `#${row.tebex_id}` }}</p>
                                    </TableCell>
                                    <TableCell class="text-sm text-muted-foreground">{{ row.category ?? '—' }}</TableCell>
                                    <TableCell>
                                        <Badge :variant="row.type === 'subscription' ? 'highlight' : 'outline'">{{
                                            row.type === 'subscription' ? 'Recurring' : 'One-off'
                                        }}</Badge>
                                        <p v-if="row.has_variables" class="mt-1 text-xs text-muted-foreground">Buyer chooses options</p>
                                    </TableCell>
                                    <TableCell class="text-right whitespace-nowrap tabular-nums">
                                        {{ formatMoney(row.base_price, row.currency) }}
                                        <p v-if="Number(row.sales_tax) > 0" class="text-xs text-muted-foreground">
                                            {{ formatMoney(row.total_price, row.currency) }} with tax
                                        </p>
                                        <p v-if="Number(row.discount) > 0" class="text-xs text-muted-foreground">
                                            {{ formatMoney(row.discount, row.currency) }} off
                                        </p>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="row.removed ? 'destructive' : 'secondary'">{{
                                            row.removed ? 'No longer listed' : 'Listed'
                                        }}</Badge>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p
                            v-else-if="filterState.search || filters.category !== null || filters.status !== 'listed'"
                            class="py-8 text-center text-sm text-muted-foreground"
                        >
                            No packages match.
                        </p>
                        <p v-else-if="!connection.configured" class="py-8 text-center text-sm text-muted-foreground">
                            Add the store's public token to the environment, then refresh the catalogue.
                        </p>
                        <p v-else class="py-8 text-center text-sm text-muted-foreground">No packages yet. Refresh the catalogue to fetch them.</p>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                    <Pagination
                        v-if="pageCount > 1"
                        v-slot="{ page: currentPage }"
                        v-model:page="page"
                        :items-per-page="Math.max(meta.per_page, 1)"
                        :total="meta.total"
                        :sibling-count="1"
                        show-edges
                    >
                        <PaginationList v-slot="{ items }" class="flex flex-wrap items-center justify-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                    <Button class="h-9 w-9 p-0" :variant="item.value === currentPage ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis v-else :index="index" />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </Pagination>
                </div>

                <Card v-if="sync.runs.length">
                    <CardHeader>
                        <CardTitle>Recent refreshes</CardTitle>
                    </CardHeader>
                    <CardContent class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Started</TableHead>
                                    <TableHead>By</TableHead>
                                    <TableHead>Result</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="run in sync.runs" :key="run.id">
                                    <TableCell class="text-sm whitespace-nowrap">{{ when(run.started_at) }}</TableCell>
                                    <TableCell class="text-sm text-muted-foreground">{{ run.by ?? triggerLabel[run.trigger] }}</TableCell>
                                    <TableCell class="text-sm">
                                        <Badge :variant="statusVariant[run.status]">{{ statusLabel[run.status] }}</Badge>
                                        <span class="ml-2 text-muted-foreground">{{ run.status === 'failed' ? run.error : summary(run) }}</span>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
