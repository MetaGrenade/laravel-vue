import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useRoles() {
    const page = usePage();
    const user = computed(() => page.props.auth.user);

    /**
     * Checks if the authenticated user has any of the specified roles.
     * Pass multiple roles separated by a pipe (|), e.g., "admin|moderator".
     * @param role A string containing one or more role names.
     * @returns true if any role is found, false otherwise.
     */
    function hasRole(role: string): boolean {
        const rolesToCheck = role.split('|').map((r) => r.trim());

        return Boolean(user.value?.roles?.some((r) => rolesToCheck.includes(r.name)));
    }

    return { hasRole };
}
