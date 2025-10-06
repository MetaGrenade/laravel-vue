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
    target: string;
    icon?: LucideIcon;
    color?: string;
    isActive?: boolean;
}

export interface User {
    id: number;
    nickname: string;
    email: string;
    avatar_url?: string | null;
    profile_bio?: string | null;
    social_links?: Array<{ label: string; url: string }> | null;
    forum_signature?: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface NotificationItem {
    id: string;
    type: string;
    title: string;
    excerpt: string | null;
    url: string | null;
    data: Record<string, unknown>;
    created_at: string | null;
    created_at_for_humans: string | null;
    read_at: string | null;
}

export interface NotificationBag {
    items: NotificationItem[];
    unread_count: number;
    has_more: boolean;
}

export interface SharedData extends PageProps {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    notifications: NotificationBag;
    ziggy: Config & { location: string };
}
