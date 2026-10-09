import { SelectGroup, SelectItem, SelectLabel } from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** A tank as any page has it: either with its fuel type nested, or with the fuel type's id/name flat. */
type TankLike = {
    id: number;
    name: string;
    fuel_type?: { id: number; name: string } | null;
    fuel_type_id?: number | null;
    fuel_type_name?: string | null;
};

/**
 * Fuel type colours, the same everywhere in the app: by the order of the station's fuel types,
 * the first (usually petrol) is amber/orange, the second (usually diesel) blue, then the rest.
 */
const DOT_COLORS = [
    'bg-amber-500',
    'bg-blue-500',
    'bg-emerald-500',
    'bg-violet-500',
    'bg-rose-500',
];

function fuelTypeOf(tank: TankLike): { id: number; name: string } {
    return {
        id: tank.fuel_type?.id ?? tank.fuel_type_id ?? 0,
        name: tank.fuel_type?.name ?? tank.fuel_type_name ?? '',
    };
}

/** Each fuel type's dot colour, from every tank of the station (not just the ones on offer). */
export function fuelTypeDotColors(tanks: TankLike[]): Record<number, string> {
    const ids = [...new Set(tanks.map((tank) => fuelTypeOf(tank).id))].sort(
        (a, b) => a - b,
    );

    return Object.fromEntries(
        ids.map((id, i) => [id, DOT_COLORS[i] ?? 'bg-slate-400']),
    );
}

export function FuelTypeDot({ className }: { className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'me-2 inline-block size-2 shrink-0 rounded-full align-middle',
                className,
            )}
        />
    );
}

/**
 * The options of a tank dropdown: each tank by its name with its fuel type's coloured dot (the
 * dot shows in the closed dropdown too), grouped under a small fuel type label when the list
 * spans more than one fuel type. Values are the tank ids, as before.
 *
 * Pass `allTanks` when `tanks` is already narrowed (to one fuel type, or without the source
 * tank), so the colours still match the rest of the app.
 */
export function TankSelectItems({
    tanks,
    allTanks,
}: {
    tanks: TankLike[];
    allTanks?: TankLike[];
}) {
    const colors = fuelTypeDotColors(allTanks ?? tanks);
    const groups = new Map<number, { name: string; tanks: TankLike[] }>();

    [...tanks]
        .sort((a, b) => fuelTypeOf(a).id - fuelTypeOf(b).id)
        .forEach((tank) => {
            const fuelType = fuelTypeOf(tank);
            const group = groups.get(fuelType.id) ?? {
                name: fuelType.name,
                tanks: [],
            };
            group.tanks.push(tank);
            groups.set(fuelType.id, group);
        });

    const items = (fuelTypeId: number, list: TankLike[]) =>
        list.map((tank) => (
            <SelectItem key={tank.id} value={String(tank.id)}>
                {/* The dot and the name are separate boxes, so the gap stays and a long name
                    truncates in its own direction without dragging the dot along. */}
                <span className="flex min-w-0 items-center" title={tank.name}>
                    <FuelTypeDot className={colors[fuelTypeId]} />
                    <span className="select-tank-name min-w-0">
                        {tank.name}
                    </span>
                </span>
            </SelectItem>
        ));

    if (groups.size <= 1) {
        return (
            <>
                {[...groups.entries()].map(([id, group]) =>
                    items(id, group.tanks),
                )}
            </>
        );
    }

    return (
        <>
            {[...groups.entries()].map(([id, group]) => (
                <SelectGroup key={id}>
                    <SelectLabel className="flex items-center">
                        <FuelTypeDot className={colors[id]} />
                        {group.name}
                    </SelectLabel>
                    {items(id, group.tanks)}
                </SelectGroup>
            ))}
        </>
    );
}
