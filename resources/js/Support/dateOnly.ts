const MONTH_ABBREVIATIONS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
] as const;

/**
 * Format an ISO date-only value without converting it to a JavaScript Date.
 *
 * Date-only academic calendar values have no timezone. Parsing them as a Date
 * can shift the calendar day when the browser applies its local timezone.
 */
export function formatDateOnly(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return value;
    }

    const [, year, monthText, dayText] = match;
    const month = Number(monthText);
    const day = Number(dayText);
    const monthName = MONTH_ABBREVIATIONS[month - 1];

    if (!monthName || day < 1 || day > 31) {
        return value;
    }

    return `${monthName} ${day}, ${year}`;
}
