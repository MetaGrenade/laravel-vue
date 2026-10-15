/** What the storefront receives about Tebex packages. Everything here is safe to show: no key, nothing private. */

export interface TebexPackageCard {
    /** The id Tebex gave the package: what its page and "add to cart" use. */
    id: number;
    name: string;
    href: string;
    image: string | null;
    /** A plain-text start of the description. */
    excerpt: string;
    category: string | null;
    recurring: boolean;
    currency: string;
    /** The price before tax: Tebex adds tax for the buyer's country when they pay. */
    price: string;
    discount: string;
    can_buy: boolean;
    /** Why it cannot be bought here (a sentence), when it cannot. */
    reason: string | null;
}

export interface TebexPackageDetail extends TebexPackageCard {
    /** The description, cleaned on the server. */
    description_html: string;
    pictures: string[];
    max_quantity: number;
    sales_tax: string;
    total_price: string;
}
