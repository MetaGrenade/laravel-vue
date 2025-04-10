import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

interface Role {
    id: number;
    name: string;
    guard_name: string;
    created_at: string;
    updated_at: string;
}

interface AuthUser {
    id: number;
    name: string;
    email: string;
    roles?: Role[];
}

export function useRoles() {
    const page = usePage();
    const user = computed<AuthUser | null>(() => page.props.auth.user || null);

    /**
     * Checks if the authenticated user has any of the specified roles.
     * Pass multiple roles separated by a pipe (|), e.g., "admin|moderator".
     * @param role A string containing one or more role names.
     * @returns true if any role is found, false otherwise.
     */
    function hasRole(role: string): boolean {
        const rolesToCheck = role.split('|').map(r => r.trim());
        return !!(
            user.value &&
            user.value.roles &&
            user.value.roles.some(r => rolesToCheck.includes(r.name))
        );
    }

    return { hasRole };
}
