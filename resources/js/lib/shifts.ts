/** A station shift as the server sends it (Shift::toOption()). */
export type ShiftOption = {
    id: number;
    position: number;
    name: string;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
};

/**
 * The shift whose times cover `time` ("HH:MM", station time), if any has times. A night shift
 * (22:00-06:00) runs past midnight; its hours after midnight still belong to the day it
 * started, so `previousDay` says the entry date should be yesterday. Shift times are only used
 * for this default; readings never depend on them.
 */
export function currentShift(
    shifts: ShiftOption[],
    time: string,
): { shift: ShiftOption; previousDay: boolean } | null {
    for (const shift of shifts) {
        const { starts_at: start, ends_at: end } = shift;

        if (!start || !end || start === end) {
            continue;
        }

        if (start < end && time >= start && time < end) {
            return { shift, previousDay: false };
        }

        if (start > end && (time >= start || time < end)) {
            return { shift, previousDay: time < end };
        }
    }

    return null;
}
