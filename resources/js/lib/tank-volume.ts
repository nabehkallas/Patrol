const TABLE_STEPS = 1000;

function segmentAreaRatio(ratio: number): number {
    if (ratio <= 0) {
        return 0;
    }

    if (ratio >= 1) {
        return 1;
    }

    const r = 0.5;
    const h = ratio;
    const cosArg = Math.min(1, Math.max(-1, (r - h) / r));
    const area = r * r * Math.acos(cosArg) - (r - h) * Math.sqrt(Math.max(0, 2 * r * h - h * h));

    return area / (Math.PI * r * r);
}

const COEFFICIENT_TABLE: number[] = Array.from({ length: TABLE_STEPS + 1 }, (_, i) => segmentAreaRatio(i / TABLE_STEPS));

export function volumeCoefficient(ratio: number): number {
    const clamped = Math.min(1, Math.max(0, ratio));
    const position = clamped * TABLE_STEPS;
    const lowerIndex = Math.floor(position);
    const upperIndex = Math.min(TABLE_STEPS, lowerIndex + 1);
    const fraction = position - lowerIndex;

    const lower = COEFFICIENT_TABLE[lowerIndex];
    const upper = COEFFICIENT_TABLE[upperIndex];

    return lower + (upper - lower) * fraction;
}

export type TankVolumeResult = {
    ratio: number;
    coefficient: number;
    volume: number;
};

export function calculateTankVolume(capacity: number, diameter: number, height: number): TankVolumeResult | null {
    if (!Number.isFinite(capacity) || !Number.isFinite(diameter) || !Number.isFinite(height)) {
        return null;
    }

    if (capacity <= 0 || diameter <= 0) {
        return null;
    }

    const ratio = height / diameter;
    const coefficient = volumeCoefficient(ratio);

    return {
        ratio: Math.min(1, Math.max(0, ratio)),
        coefficient,
        volume: capacity * coefficient,
    };
}
