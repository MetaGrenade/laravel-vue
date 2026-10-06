/**
 * Translation helpers that mirror Laravel's `__()` / `trans_choice()` message
 * syntax, so one set of lang/*.php strings serves both PHP and Vue.
 *
 * Kept free of Vue and Inertia imports so it is easy to test and to reuse.
 */

export type Replacements = Record<string, string | number>;

const capitalise = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

/**
 * Replace `:name`, `:Name` (capitalised) and `:NAME` (upper-case) placeholders.
 * Placeholders without a matching replacement are left untouched.
 */
export function interpolate(message: string, replacements: Replacements = {}): string {
    return message.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (placeholder: string, name: string) => {
        if (name in replacements) {
            return String(replacements[name]);
        }

        const lower = name.toLowerCase();

        if (!(lower in replacements)) {
            return placeholder;
        }

        const value = String(replacements[lower]);

        if (name === name.toUpperCase()) {
            return value.toUpperCase();
        }

        return name.charAt(0) === name.charAt(0).toUpperCase() ? capitalise(value) : value;
    });
}

interface Segment {
    text: string;
    matches: ((count: number) => boolean) | null;
}

function parseSegment(raw: string): Segment {
    const exact = raw.match(/^\{(\d+)\}\s*([\s\S]*)$/);

    if (exact) {
        const expected = Number(exact[1]);

        return { text: exact[2], matches: (count) => count === expected };
    }

    const range = raw.match(/^\[(\d+|\*),\s*(\d+|\*)\]\s*([\s\S]*)$/);

    if (range) {
        const min = range[1] === '*' ? -Infinity : Number(range[1]);
        const max = range[2] === '*' ? Infinity : Number(range[2]);

        return { text: range[3], matches: (count) => count >= min && count <= max };
    }

    return { text: raw, matches: null };
}

/**
 * Pick the right form of a pluralised message, e.g. `one apple|:count apples`
 * or `{0} none|{1} one|[2,*] many`. Without explicit conditions the first form
 * is used for the singular and the second for everything else.
 */
export function choose(message: string, count: number, locale = 'en'): string {
    const segments = message.split('|').map((part) => parseSegment(part.trim()));

    for (const segment of segments) {
        if (segment.matches?.(count)) {
            return segment.text;
        }
    }

    const forms = segments.filter((segment) => segment.matches === null);

    if (forms.length === 0) {
        return segments[segments.length - 1]?.text ?? message;
    }

    const category = new Intl.PluralRules(locale).select(count);
    const index = category === 'one' ? 0 : Math.min(1, forms.length - 1);

    return forms[index].text;
}
