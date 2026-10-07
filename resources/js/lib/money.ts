/**
 * Format a decimal amount from the server (for example "19.99") for display.
 * The server does all arithmetic; this only presents the result.
 */
export function formatMoney(amount: string | number, currency: string): string {
    const value = Number(amount);

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(Number.isFinite(value) ? value : 0);
}
