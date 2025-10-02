import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';
import type { Config } from 'ziggy-js';

export interface Auth {
    user: User | null;
    permissions: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    color?: string;
    isActive?: boolean;
}

export interface SharedData extends PageProps {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    notifications: NotificationItem[];
    ziggy: Config & { location: string };
}

export interface User {
    id: number;
    nickname: string;
    email: string;
    avatar_url?: string | null;
    forum_signature?: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface NotificationItem {
    id: string;
    type: string;
    data: Record<string, unknown>;
    created_at: string | null;
    read_at: string | null;
}
