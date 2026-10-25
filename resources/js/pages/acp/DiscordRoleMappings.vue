<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type BreadcrumbItem } from '@/types';
import {
    discordAdminApi,
    type DiscordGuildRole,
    type DiscordRoleMapping,
} from '@/lib/discord-api';

const props = defineProps<{
    spatieRoles: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Discord role mappings', href: '/acp/discord/role-mappings' },
];

const mappings = ref<DiscordRoleMapping[]>([]);
const discordRoles = ref<DiscordGuildRole[]>([]);
const loading = ref(true);
const saving = ref(false);

const form = ref({
    spatie_role_name: '',
    discord_role_id: '',
    sync_to_discord: true,
    sync_from_discord: false,
});

const discordRoleOptions = computed(() =>
    discordRoles.value.map((role) => ({
        value: role.id,
        label: role.name,
    })),
);

const loadData = async () => {
    loading.value = true;

    try {
        const [mappingResponse, rolesResponse] = await Promise.all([
            discordAdminApi.listRoleMappings(),
            discordAdminApi.listDiscordRoles().catch(() => ({ data: [] as DiscordGuildRole[] })),
        ]);

        mappings.value = mappingResponse.data;
        discordRoles.value = rolesResponse.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to load Discord role mappings.');
    } finally {
        loading.value = false;
    }
};

const createMapping = async () => {
    if (!form.value.spatie_role_name || !form.value.discord_role_id) {
        toast.error('Choose a Spatie role and a Discord role.');

        return;
    }

    saving.value = true;

    try {
        const response = await discordAdminApi.createRoleMapping(form.value);
        mappings.value = [...mappings.value, response.data].sort((a, b) => a.spatie_role_name.localeCompare(b.spatie_role_name));
        form.value.discord_role_id = '';
        toast.success('Role mapping created.');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to create mapping.');
    } finally {
        saving.value = false;
    }
};

const updateMapping = async (mapping: DiscordRoleMapping, patch: Partial<DiscordRoleMapping>) => {
    try {
        const response = await discordAdminApi.updateRoleMapping(mapping.id, patch);
        mappings.value = mappings.value.map((row) => (row.id === mapping.id ? response.data : row));
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to update mapping.');
    }
};

const deleteMapping = async (mapping: DiscordRoleMapping) => {
    try {
        await discordAdminApi.deleteRoleMapping(mapping.id);
        mappings.value = mappings.value.filter((row) => row.id !== mapping.id);
        toast.success('Mapping removed.');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to delete mapping.');
    }
};

onMounted(loadData);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Discord role mappings" />

        <AdminLayout>
            <div class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Add mapping</CardTitle>
                        <CardDescription>
                            Map Spatie roles to Discord guild roles for automated sync. Discord roles load when
                            <code class="text-xs">DISCORD_BOT_TOKEN</code> and <code class="text-xs">DISCORD_GUILD_ID</code> are set.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4 md:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="spatie-role">Spatie role</Label>
                            <select
                                id="spatie-role"
                                v-model="form.spatie_role_name"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs"
                            >
                                <option value="" disabled>Select role</option>
                                <option v-for="role in props.spatieRoles" :key="role" :value="role">{{ role }}</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="discord-role">Discord role</Label>
                            <select
                                id="discord-role"
                                v-model="form.discord_role_id"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs"
                            >
                                <option value="" disabled>Select Discord role</option>
                                <option v-for="role in discordRoleOptions" :key="role.value" :value="role.value">
                                    {{ role.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <Switch id="sync-to" v-model:checked="form.sync_to_discord" />
                            <Label for="sync-to">Sync to Discord</Label>
                        </div>
                        <div class="flex items-center gap-2">
                            <Switch id="sync-from" v-model:checked="form.sync_from_discord" />
                            <Label for="sync-from">Sync from Discord (phase 3)</Label>
                        </div>
                        <div class="md:col-span-2">
                            <Button type="button" :disabled="saving" @click="createMapping">Add mapping</Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Current mappings</CardTitle>
                        <CardDescription v-if="loading">Loading…</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table v-if="!loading && mappings.length">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Spatie role</TableHead>
                                    <TableHead>Discord role ID</TableHead>
                                    <TableHead>To Discord</TableHead>
                                    <TableHead>From Discord</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="mapping in mappings" :key="mapping.id">
                                    <TableCell>{{ mapping.spatie_role_name }}</TableCell>
                                    <TableCell class="font-mono text-xs">{{ mapping.discord_role_id }}</TableCell>
                                    <TableCell>
                                        <Switch
                                            :checked="mapping.sync_to_discord"
                                            @update:checked="(value: boolean) => updateMapping(mapping, { sync_to_discord: value })"
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Switch
                                            :checked="mapping.sync_from_discord"
                                            @update:checked="(value: boolean) => updateMapping(mapping, { sync_from_discord: value })"
                                        />
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <Button variant="destructive" size="sm" @click="deleteMapping(mapping)">Delete</Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <p v-else-if="!loading" class="text-sm text-muted-foreground">No mappings yet.</p>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
