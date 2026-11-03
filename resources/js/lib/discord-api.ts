import { jsonHeaders } from '@/lib/http';

const adminBase = '/discord/admin';
const userBase = '/discord/user';

async function parseJson<T>(response: Response): Promise<T> {
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const message = typeof body?.message === 'string' ? body.message : response.statusText;

        throw new Error(message || 'Request failed');
    }

    if (response.status === 204) {
        return undefined as T;
    }

    return (await response.json()) as T;
}

function request<T>(url: string, init?: RequestInit): Promise<T> {
    return fetch(url, init).then((response) => parseJson<T>(response));
}

export type DiscordRoleMapping = {
    id: number;
    spatie_role_name: string;
    discord_role_id: string;
    sync_to_discord: boolean;
    sync_from_discord: boolean;
};

export type DiscordGuildRole = {
    id: string;
    name: string;
    color?: number;
    position?: number;
};

export type DiscordSyncFailure = {
    id: number;
    operation: string;
    user_id: number | null;
    discord_user_id: string | null;
    guild_id: string | null;
    error: string;
    attempts: number;
    last_failed_at: string;
    resolved_at: string | null;
};

export type DiscordSyncFailureRetryResult = {
    retried: number;
    skipped: number;
};

export type DiscordSyncStatus = {
    running: boolean;
    stale_linked_users: number;
    latest_run: Record<string, unknown> | null;
    unresolved_failures?: number;
};

export type DiscordNotificationPreferences = {
    categories: Record<string, string>;
    preferences: Record<string, boolean>;
};

export const discordAdminApi = {
    listRoleMappings(): Promise<{ data: DiscordRoleMapping[] }> {
        return request(`${adminBase}/role-mappings`, { headers: jsonHeaders() });
    },
    createRoleMapping(payload: Omit<DiscordRoleMapping, 'id'>): Promise<{ data: DiscordRoleMapping }> {
        return request(`${adminBase}/role-mappings`, {
            method: 'POST',
            headers: jsonHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(payload),
        });
    },
    updateRoleMapping(id: number, payload: Partial<Omit<DiscordRoleMapping, 'id'>>): Promise<{ data: DiscordRoleMapping }> {
        return request(`${adminBase}/role-mappings/${id}`, {
            method: 'PUT',
            headers: jsonHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(payload),
        });
    },
    deleteRoleMapping(id: number): Promise<void> {
        return request(`${adminBase}/role-mappings/${id}`, {
            method: 'DELETE',
            headers: jsonHeaders(),
        });
    },
    listDiscordRoles(): Promise<{ data: DiscordGuildRole[] }> {
        return request(`${adminBase}/discord-roles`, { headers: jsonHeaders() });
    },
    syncStatus(): Promise<DiscordSyncStatus> {
        return request(`${adminBase}/sync/status`, { headers: jsonHeaders() });
    },
    syncRuns(page = 1): Promise<{ data: Record<string, unknown>[]; meta?: Record<string, unknown> }> {
        return request(`${adminBase}/sync/runs?per_page=15&page=${page}`, { headers: jsonHeaders() });
    },
    syncUser(userId: number, options: { sync?: boolean; dryRun?: boolean } = {}): Promise<Record<string, unknown>> {
        const params = new URLSearchParams();

        if (options.sync) {
            params.set('sync', '1');
        }

        if (options.dryRun) {
            params.set('dry_run', '1');
        }

        const query = params.toString();

        return request(`${adminBase}/sync/users/${userId}${query ? `?${query}` : ''}`, {
            method: 'POST',
            headers: jsonHeaders(),
        });
    },
    listSyncFailures(options: { page?: number; perPage?: number; resolved?: boolean | null } = {}): Promise<{
        data: DiscordSyncFailure[];
        meta?: Record<string, unknown>;
    }> {
        const params = new URLSearchParams();
        params.set('per_page', String(options.perPage ?? 15));

        if (options.page !== undefined && options.page > 0) {
            params.set('page', String(options.page));
        }

        if (options.resolved === true) {
            params.set('resolved', '1');
        } else if (options.resolved === false) {
            params.set('resolved', '0');
        }

        return request(`${adminBase}/sync/failures?${params.toString()}`, { headers: jsonHeaders() });
    },
    retrySyncFailures(payload: { limit?: number; ids?: number[] } = {}): Promise<DiscordSyncFailureRetryResult> {
        return request(`${adminBase}/sync/failures/retry`, {
            method: 'POST',
            headers: jsonHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(payload),
        });
    },
};

export const discordUserApi = {
    notificationPreferences(): Promise<DiscordNotificationPreferences> {
        return request(`${userBase}/notification-preferences`, { headers: jsonHeaders() });
    },
    updateNotificationPreferences(preferences: Record<string, boolean>): Promise<{ preferences: Record<string, boolean> }> {
        return request(`${userBase}/notification-preferences`, {
            method: 'PUT',
            headers: jsonHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify({ preferences }),
        });
    },
};
