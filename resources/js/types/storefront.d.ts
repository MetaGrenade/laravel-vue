/** Wishlists and reviews as the storefront receives them. */
import type { StorefrontImage, StorefrontPrice, StorefrontVariant } from '@/types/commerce';

interface Taxon {
    id: number;
    name: string;
    slug: string;
}

/** What published reviews say about a product, for a card. Absent when nobody has reviewed it. */
export interface StorefrontRating {
    /** To one decimal. */
    average: number;
    count: number;
}

/** A product as a card shows it (the catalogue, the wishlist). */
export interface StorefrontCard {
    id: number;
    name: string;
    slug: string;
    description?: string | null;
    /** Decided by the server with the same rules checkout uses, so the button is never on for something checkout would refuse. */
    can_buy: boolean;
    sold_out: boolean;
    image: StorefrontImage | null;
    variants: StorefrontVariant[];
    prices: StorefrontPrice[];
    brand?: Taxon | null;
    categories: Taxon[];
    tags: Taxon[];
    rating?: StorefrontRating | null;
    /** Whether the signed-in customer saved it. */
    wishlisted?: boolean;
}

/** One published review. */
export interface ProductReviewItem {
    id: number;
    rating: number;
    title: string | null;
    body: string;
    author: string;
    verified_purchase: boolean;
    created_at: string | null;
    /** Written by the person looking at the page. */
    mine: boolean;
}

/** The visitor's own review, whatever state it is in. */
export interface MyProductReview {
    rating: number;
    title: string | null;
    body: string;
    status: 'pending' | 'published' | 'rejected';
    status_label: string;
    verified_purchase: boolean;
    created_at: string | null;
}

export type ReviewSort = 'newest' | 'highest' | 'lowest';

/** What the product page receives about reviews; null when reviews are switched off. */
export interface ProductReviewsPayload {
    summary: {
        count: number;
        average: number | null;
        /** How many reviews gave each number of stars. */
        distribution: Record<number, number>;
    };
    sort: ReviewSort;
    items: ProductReviewItem[];
    page: { current: number; last: number; total: number };
    mine: MyProductReview | null;
    /** The visitor can write (or change) their review. */
    can_write: boolean;
    /** Why not, as a code (`login`, `banned`, `verify_email`, `purchase`), and in words. */
    reason: string | null;
    message: string | null;
    limits: { title_max: number; body_min: number; body_max: number };
}
