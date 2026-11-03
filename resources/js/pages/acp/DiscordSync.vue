<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';
import { discordAdminApi, type DiscordSyncFailure, type DiscordSyncStatus } from '@/lib/discord-api';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Discord sync', href: '/acp/discord/sync' }];

const status = ref<DiscordSyncStatus | null>(null);
const runs = ref<Record<string, unknown>[]>([]);
const failures = ref<DiscordSyncFailure[]>([]);
const failuresMeta = ref<Record<string, unknown> | null>(null);
const failuresPage = ref(1);
const failuresPerPage = ref(15);
const failuresFilter = ref<'unresolved' | 'resolved' | 'all'>('unresolved');
const failuresLoading = ref(false);
const failuresApiAvailable = ref(true);
const selectedFailureIds = ref<number[]>([]);
const retryLimit = ref('50');
const loading = ref(true);
const userId = ref('');
const syncing = ref(false);
const retrying = ref(false);

const displayedFailures = computed(() => {
    if (failuresFilter.value === 'resolved') {
        return failures.value.filter((row) => row.resolved_at !== null);
    }

    return failures.value;
});

const unresolvedFailureCount = computed(() => status.value?.unresolved_failures ?? null);

const allVisibleFailuresSelected = computed(() => {
    const unresolvedRows = failures.value.filter((row) => row.resolved_at === null);

    return unresolvedRows.length > 0 && unresolvedRows.every((row) => selectedFailureIds.value.includes(row.id));
});

const loadFailures = async () => {
    failuresLoading.value = true;

    try {
        const response = await discordAdminApi.listSyncFailures({
            page: failuresPage.value,
            perPage: failuresPerPage.value,
            unresolved: failuresFilter.value === 'unresolved',
        });

        failures.value = response.data ?? [];
        failuresMeta.value = response.meta ?? null;
        failuresApiAvailable.value = true;
        selectedFailureIds.value = selectedFailureIds.value.filter((id) => failures.value.some((row) => row.id === id));
    } catch (error) {
        failures.value = [];
        failuresMeta.value = null;

        if (error instanceof Error && /not found/i.test(error.message)) {
            failuresApiAvailable.value = false;
        } else {
            toast.error(error instanceof Error ? error.message : 'Unable to load sync failures.');
        }
    } finally {
        failuresLoading.value = false;
    }
};

const load = async () => {
    loading.value = true;

    try {
        const [statusResponse, runsResponse] = await Promise.all([discordAdminApi.syncStatus(), discordAdminApi.syncRuns()]);

        status.value = statusResponse;
        runs.value = runsResponse.data ?? [];
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to load sync status.');
    } finally {
        loading.value = false;
    }

    await loadFailures();
};

const queueUserSync = async (options: { sync?: boolean; dryRun?: boolean } = {}) => {
    const id = Number.parseInt(userId.value, 10);

    if (!Number.isFinite(id) || id <= 0) {
        toast.error('Enter a valid user ID.');

        return;
    }

    syncing.value = true;

    try {
        const result = await discordAdminApi.syncUser(id, options);
        toast.success(typeof result.message === 'string' ? result.message : result.queued ? `Queued sync for user #${id}.` : 'Sync requested.');
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Sync failed.');
    } finally {
        syncing.value = false;
    }
};

const toggleFailureSelection = (failure: DiscordSyncFailure, checked: boolean) => {
    if (failure.resolved_at !== null) {
        return;
    }

    if (checked) {
        selectedFailureIds.value = [...new Set([...selectedFailureIds.value, failure.id])];
    } else {
        selectedFailureIds.value = selectedFailureIds.value.filter((id) => id !== failure.id);
    }
};

const toggleSelectAllVisible = (checked: boolean) => {
    if (!checked) {
        selectedFailureIds.value = [];

        return;
    }

    selectedFailureIds.value = failures.value.filter((row) => row.resolved_at === null).map((row) => row.id);
};

const applyFailuresFilter = async (filter: 'unresolved' | 'resolved' | 'all') => {
    failuresFilter.value = filter;
    failuresPage.value = 1;
    selectedFailureIds.value = [];
    await loadFailures();
};

const changeFailuresPage = async (nextPage: number) => {
    failuresPage.value = Math.max(1, nextPage);
    await loadFailures();
};

const retryFailures = async (payload: { limit?: number; ids?: number[] }) => {
    retrying.value = true;

    try {
        const result = await discordAdminApi.retrySyncFailures(payload);
        toast.success(`Retried ${result.retried} failure(s)${result.skipped ? `; skipped ${result.skipped}.` : '.'}`);
        selectedFailureIds.value = [];
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Retry failed.');
    } finally {
        retrying.value = false;
    }
};

const retrySelectedFailures = async () => {
    if (selectedFailureIds.value.length === 0) {
        toast.error('Select at least one unresolved failure.');

        return;
    }

    await retryFailures({ ids: selectedFailureIds.value });
};

const retryBatchFailures = async () => {
    const limit = Number.parseInt(retryLimit.value, 10);

    if (!Number.isFinite(limit) || limit <= 0) {
        toast.error('Enter a valid retry limit.');

        return;
    }

    await retryFailures({ limit });
};

const failuresCurrentPage = computed(() => Number(failuresMeta.value?.current_page ?? failuresPage.value));
const failuresLastPage = computed(() => Number(failuresMeta.value?.last_page ?? 1));

onMounted(load);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Discord sync" />

        <AdminLayout>
            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Overview</CardTitle>
                        <CardDescription>
                            Stale linked users are synced hourly when
                            <code class="text-xs">DISCORD_SCHEDULE_STALE_SYNC=true</code> and the scheduler is running.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <p class="text-sm text-muted-foreground">Sync running</p>
                            <p class="text-lg font-semibold">{{ status?.running ? 'Yes' : 'No' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Stale linked users</p>
                            <p class="text-lg font-semibold">{{ status?.stale_linked_users ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Unresolved DLQ</p>
                            <p class="text-lg font-semibold">{{ unresolvedFailureCount ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Latest run status</p>
                            <p class="text-lg font-semibold">{{ (status?.latest_run?.status as string) ?? '—' }}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Manual user sync</CardTitle>
                        <CardDescription>Queue a job by default, or run immediately with dry-run support.</CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="flex flex-1 flex-col gap-2">
                            <Label for="user-id">User ID</Label>
                            <Input id="user-id" v-model="userId" inputmode="numeric" placeholder="e.g. 42" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button :disabled="syncing" @click="queueUserSync()">Queue sync</Button>
                            <Button variant="secondary" :disabled="syncing" @click="queueUserSync({ sync: true })">Run now</Button>
                            <Button variant="outline" :disabled="syncing" @click="queueUserSync({ sync: true, dryRun: true })"> Dry run </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Sync dead-letter queue</CardTitle>
                        <CardDescription>
                            Failed role sync jobs recorded in
                            <code class="text-xs">discord_sync_failures</code>. Retry re-dispatches jobs (same as
                            <code class="text-xs">php artisan discord:retry-failures</code>).
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <p v-if="!failuresApiAvailable" class="text-sm text-muted-foreground">
                            The companion admin API for sync failures is not available yet. Upgrade
                            <code class="text-xs">metagrenade/metaforge-discord</code> once
                            <code class="text-xs">GET /discord/admin/sync/failures</code> is published.
                        </p>
                        <template v-else>
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        :variant="failuresFilter === 'unresolved' ? 'default' : 'outline'"
                                        @click="applyFailuresFilter('unresolved')"
                                    >
                                        Unresolved
                                    </Button>
                                    <Button
                                        size="sm"
                                        :variant="failuresFilter === 'resolved' ? 'default' : 'outline'"
                                        @click="applyFailuresFilter('resolved')"
                                    >
                                        Resolved
                                    </Button>
                                    <Button size="sm" :variant="failuresFilter === 'all' ? 'default' : 'outline'" @click="applyFailuresFilter('all')">
                                        All
                                    </Button>
                                </div>
                                <div class="flex flex-wrap items-end gap-2 sm:ml-auto">
                                    <div class="flex flex-col gap-1">
                                        <Label for="retry-limit" class="text-xs">Batch limit</Label>
                                        <Input id="retry-limit" v-model="retryLimit" class="w-24" inputmode="numeric" />
                                    </div>
                                    <Button variant="secondary" size="sm" :disabled="retrying" @click="retryBatchFailures"> Retry batch </Button>
                                    <Button size="sm" :disabled="retrying || selectedFailureIds.length === 0" @click="retrySelectedFailures">
                                        Retry selected ({{ selectedFailureIds.length }})
                                    </Button>
                                </div>
                            </div>

                            <p v-if="failuresLoading" class="text-sm text-muted-foreground">Loading failures…</p>
                            <Table v-else-if="displayedFailures.length">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="w-10">
                                            <Checkbox
                                                :model-value="allVisibleFailuresSelected"
                                                :disabled="failuresFilter === 'resolved'"
                                                @update:model-value="(value) => toggleSelectAllVisible(value === true)"
                                            />
                                        </TableHead>
                                        <TableHead>ID</TableHead>
                                        <TableHead>Operation</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Discord user</TableHead>
                                        <TableHead>Attempts</TableHead>
                                        <TableHead>Last failed</TableHead>
                                        <TableHead>Error</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="failure in displayedFailures" :key="failure.id">
                                        <TableCell>
                                            <Checkbox
                                                :model-value="selectedFailureIds.includes(failure.id)"
                                                :disabled="failure.resolved_at !== null"
                                                @update:model-value="(value) => toggleFailureSelection(failure, value === true)"
                                            />
                                        </TableCell>
                                        <TableCell>{{ failure.id }}</TableCell>
                                        <TableCell class="font-mono text-xs">{{ failure.operation }}</TableCell>
                                        <TableCell>{{ failure.user_id ?? '—' }}</TableCell>
                                        <TableCell class="font-mono text-xs">{{ failure.discord_user_id ?? '—' }}</TableCell>
                                        <TableCell>{{ failure.attempts }}</TableCell>
                                        <TableCell class="text-xs whitespace-nowrap">{{ failure.last_failed_at }}</TableCell>
                                        <TableCell class="max-w-xs truncate text-xs" :title="failure.error">{{ failure.error }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                            <p v-else class="text-sm text-muted-foreground">No sync failures in this view.</p>

                            <div v-if="failuresLastPage > 1" class="flex items-center justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="failuresLoading || failuresCurrentPage <= 1"
                                    @click="changeFailuresPage(failuresCurrentPage - 1)"
                                >
                                    Previous
                                </Button>
                                <span class="text-sm text-muted-foreground">Page {{ failuresCurrentPage }} of {{ failuresLastPage }}</span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="failuresLoading || failuresCurrentPage >= failuresLastPage"
                                    @click="changeFailuresPage(failuresCurrentPage + 1)"
                                >
                                    Next
                                </Button>
                            </div>
                        </template>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent runs</CardTitle>
                        <CardDescription v-if="loading">Loading…</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table v-if="!loading && runs.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Trigger</TableHead>
                                    <TableHead>Started</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="run in runs" :key="String(run.id)">
                                    <TableCell>{{ run.id }}</TableCell>
                                    <TableCell>{{ run.status }}</TableCell>
                                    <TableCell>{{ run.trigger ?? '—' }}</TableCell>
                                    <TableCell>{{ run.started_at ?? '—' }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else-if="!loading" class="text-sm text-muted-foreground">No sync runs recorded yet.</p>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
