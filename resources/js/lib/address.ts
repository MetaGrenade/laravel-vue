import type { AddressFormValue } from '@/types/commerce';

export function emptyAddress(country = ''): AddressFormValue {
    return { name: '', company: '', line1: '', line2: '', city: '', region: '', postal_code: '', country, phone: '' };
}

/** The country's name in the visitor's language, falling back to its code. */
export function countryName(code: string, locale?: string): string {
    if (!code) {
        return '';
    }

    try {
        return new Intl.DisplayNames(locale ? [locale] : undefined, { type: 'region' }).of(code.toUpperCase()) ?? code;
    } catch {
        return code;
    }
}

/** Country codes with their names, sorted by name for a picker. */
export function countryOptions(codes: string[], locale?: string): { code: string; name: string }[] {
    return codes.map((code) => ({ code, name: countryName(code, locale) })).sort((a, b) => a.name.localeCompare(b.name, locale));
}

/** An address on a few lines, for showing a saved or ordered address. */
export function addressLines(address: Partial<AddressFormValue> | null | undefined): string[] {
    if (!address) {
        return [];
    }

    const cityLine = [address.city, address.region, address.postal_code].filter(Boolean).join(', ');

    return [address.name, address.company, address.line1, address.line2, cityLine, address.country ? countryName(address.country) : ''].filter(
        (line): line is string => Boolean(line && line.trim()),
    );
}
