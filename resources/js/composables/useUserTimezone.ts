// resources/js/composables/useUserTimezone.ts
import { ref, watch } from 'vue'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'
import relativeTime from 'dayjs/plugin/relativeTime'

// Extend dayjs with the plugins
dayjs.extend(utc)
dayjs.extend(timezone)
dayjs.extend(relativeTime)

export function useUserTimezone(fallbackZone?: string) {
    // Guess from browser; if that returns empty, fall back:
    const tz = dayjs.tz?.guess() || fallbackZone || 'UTC';
    const timezone = ref(tz);

    /**
     * Change the user's timezone (and update Day.js default).
     */
    function setTimeZone(tz: string) {
        timezone.value = tz
        dayjs.tz.setDefault(timezone.value)
    }

    /**
     * Format a date in the user's timezone.
     * @param d anything Day.js accepts (Date, string, number)
     * @param fmt
     */
    function formatDate(d: dayjs.ConfigType, fmt = 'YYYY‑MM‑DD HH:mm:ss') {
        return dayjs.utc(d).tz(timezone.value).format(fmt);
    }

    /**
     * Return a relative time string (e.g. "3 hours ago").
     */
    function fromNow(d: dayjs.ConfigType) {
        // parse as UTC first, then shift to user zone
        return dayjs.utc(d).tz(timezone.value).fromNow();
    }

    // If you ever allow the user to change TZ at runtime, you could watch it:
    watch(timezone, (tz) => {
        dayjs.tz.setDefault(tz)
    })

    return {
        setTimeZone,
        formatDate,
        fromNow,
        timezone,
    }
}
