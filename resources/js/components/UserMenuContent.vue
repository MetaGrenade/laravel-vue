<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { SharedData, User } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut, Mail, Settings, Users } from '@lucide/vue';

interface Props {
    user: User;
}

defineProps<Props>();

const page = usePage<SharedData>();
const { t } = useI18n();
const forumEnabled = computed(() => page.props.settings.website_sections.forum);
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('profile.edit')" as="button">
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('messages.index')" as="button">
                <Mail class="mr-2 h-4 w-4" />
                {{ t('members.messages.menu') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="forumEnabled" :as-child="true">
            <Link class="block w-full" :href="route('forum.following')" as="button">
                <Users class="mr-2 h-4 w-4" />
                {{ t('members.following.menu') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full" method="post" :href="route('logout')" as="button">
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
