import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
    const page = usePage();
    // We assume permissions are shared as an array of permission names.
    const permissions = computed<string[]>(() => page.props.auth.permissions || []);

    /**
     * Checks if the authenticated user has any of the specified permissions.
     * Pass multiple permissions separated by a pipe (|), e.g., "users.acp.manage|blogs.acp.manage".
     * @param permission A string containing one or more permission names.
     * @returns true if any permission is found, false otherwise.
     */
    function hasPermission(permission: string): boolean {
        const permissionsToCheck = permission.split('|').map(p => p.trim());
        return permissions.value.some(p => permissionsToCheck.includes(p));
    }

    return { hasPermission };
}
