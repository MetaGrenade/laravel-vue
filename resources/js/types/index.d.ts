import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from '@lucide/vue';
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
    reputation_points?: number;
    badges?: Array<UserBadge>;
    roles?: Array<{ id: number; name: string }>;
    timezone: string;
    locale: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface UserBadge {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    points_required: number;
    awarded_at: string | null;
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

export interface CartItemSummary {
    id: number;
    name: string;
    /** Product slug for linking back to the product page; null if the product was deleted. */
    slug?: string | null;
    /** Thumbnail of the product's main picture, if it has one. */
    image?: string | null;
    variant: string | null;
    quantity: number;
    unit_price: string;
    total: string;
}

export interface CartSummary {
    id: number;
    currency: string;
    subtotal: string;
    /** Total units across all lines. */
    count: number;
    items: CartItemSummary[];
}

export interface SharedData extends PageProps {
    name: string;
    /** Active interface language (see config/i18n.php). */
    locale: string;
    locales: string[];
    /** Flattened translations for the shared groups, e.g. `{ 'ui.nav.home': 'Home' }`. */
    translations: Record<string, string>;
    quote: { message: string; author: string };
    auth: Auth;
    notifications: NotificationBag;
    /** `routes` is only present when the route map must be (re)loaded; see HandleInertiaRequests::ziggy(). */
    ziggy: Partial<Config> & { location: string; group: 'public' | 'staff' };
    seoHead: string[];
    flash: {
        success?: string | null;
        error?: string | null;
        warning?: string | null;
        info?: string | null;
        plain_text_token?: string | null;
    };
    billing: { stripeKey: string | null };
    settings: {
        website_sections: Record<'blog' | 'forum' | 'support' | 'commerce', boolean>;
        oauth_providers: Record<string, boolean>;
    };
    cart: CartSummary | null;
}

/**
 * Query string / request data accepted by Inertia's router (router.get, router.post, ...).
 */
export type QueryParams = Record<string, import('@inertiajs/core').FormDataConvertible>;
