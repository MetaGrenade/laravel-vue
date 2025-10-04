import { ref } from 'vue';

const sharedQuery = ref('');

export function useGlobalSearchQuery() {
    return sharedQuery;
}
