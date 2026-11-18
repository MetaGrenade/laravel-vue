// A time typed into an admin form (docs/upgrading.md, "Times typed in the admin area").
//
// A `datetime-local` box holds a wall clock with no zone: "2027-03-10T09:00" is 09:00 on the admin's own clock, and means a different moment
// in Denver than in Tokyo. Posted as it is, the server reads it in the site's zone (UTC), so a post scheduled for 09:00 in Denver went live
// at 09:00 UTC, which is 02:00 there; and the page that loads it back turns the stored moment into the admin's wall clock, so saving a form
// without touching the time moved it again by the same hours. A form sends the moment the box names (`instantFromInput`) and fills the box
// from a moment the server sent (`inputFromInstant`). Both are the browser's own zone, which is the clock the admin is looking at.

const pad = (value: number): string => String(value).padStart(2, '0');

/**
 * What a `datetime-local` box holds for a moment: its wall clock in the browser's zone, to the minute (`2027-03-10T09:00`). Empty when
 * there is no moment or the value is not a date.
 */
export function inputFromInstant(value: Date | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * The moment a `datetime-local` box names, as an ISO date in UTC (`2027-03-10T16:00:00.000Z` for 09:00 in Denver). An empty box stays empty,
 * and a value that is not a date comes back as it was, for the server to refuse.
 */
export function instantFromInput(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toISOString();
}
