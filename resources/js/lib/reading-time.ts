/** When a record shows as having happened; see Transaction::shownTime() (`ShownTime` in types). */
export type ReadingShownTime = {
    at: string;
    entered: { at: string; shift: string | null } | null;
};

/**
 * A reading's time in the history, by the same rule as a fuel sale's: saved on its own date, the
 * time it was saved; saved on another day, its date only ('YYYY-MM-DD'), with when it was
 * entered (and its shift, when given). `savedDay` is the station-time day of `createdAt`
 * (stationDateOf()), passed in so this stays free of the station's settings.
 */
export function readingShownTime(
    date: string,
    createdAt: string,
    savedDay: string,
    shift: string | null = null,
): ReadingShownTime {
    const day = date.slice(0, 10);

    return savedDay === day
        ? { at: createdAt, entered: null }
        : { at: day, entered: { at: createdAt, shift } };
}
