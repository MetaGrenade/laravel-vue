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
    ziggy: Config & { location: string };
    flash?: Record<string, unknown>;
}

export interface User {
    id: number;
    nickname: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    is_banned?: boolean;
}

export interface BlogComment {
    id: number;
    body: string;
    created_at: string;
    updated_at: string;
    user: (Pick<User, 'id' | 'nickname' | 'avatar'> & { avatar?: string | null }) | null;
}

export type BreadcrumbItemType = BreadcrumbItem;
