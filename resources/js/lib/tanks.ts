/**
 * Tanks offered in a selector for a NEW assignment (a transfer, a reading, a delivery, ...):
 * inactive tanks are hidden, since "inactive" means "don't offer this for new operations" --
 * except the tank already selected in this exact form, if any, so an edit form never renders
 * a blank/broken value for a record that legitimately points at a tank since turned inactive.
 */
export function selectableTanks<T extends { id: number; is_active?: boolean }>(
    tanks: T[],
    currentTankId?: number | string | null,
): T[] {
    return tanks.filter(
        (tank) =>
            tank.is_active !== false ||
            (currentTankId !== undefined &&
                currentTankId !== null &&
                String(tank.id) === String(currentTankId)),
    );
}
