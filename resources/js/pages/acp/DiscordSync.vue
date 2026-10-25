<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';
import { discordAdminApi, type DiscordSyncStatus } from '@/lib/discord-api';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Discord sync', href: '/acp/discord/sync' }];

const status = ref<DiscordSyncStatus | null>(null);
const runs = ref<Record<string, unknown>[]>([]);
const loading = ref(true);
const userId = ref('');
const syncing = ref(false);

const load = async () => {
    loading.value = true;

    try {
        const [statusResponse, runsResponse] = await Promise.all([
            discordAdminApi.syncStatus(),
            discordAdminApi.syncRuns(),
        ]);

        status.value = statusResponse;
        runs.value = runsResponse.data ?? [];
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to load sync status.');
    } finally {
        loading.value = false;
    }
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
        toast.success(
            typeof result.message === 'string'
                ? result.message
                : result.queued
                  ? `Queued sync for user #${id}.`
                  : 'Sync requested.',
        );
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Sync failed.');
    } finally {
        syncing.value = false;
    }
};

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
                    <CardContent class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-sm text-muted-foreground">Sync running</p>
                            <p class="text-lg font-semibold">{{ status?.running ? 'Yes' : 'No' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Stale linked users</p>
                            <p class="text-lg font-semibold">{{ status?.stale_linked_users ?? '—' }}</p>
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
                            <Button variant="outline" :disabled="syncing" @click="queueUserSync({ sync: true, dryRun: true })">
                                Dry run
                            </Button>
                        </div>
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
