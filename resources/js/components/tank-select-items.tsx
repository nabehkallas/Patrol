import { SelectGroup, SelectItem, SelectLabel } from '@/components/ui/select';
import { useFuelColors } from '@/lib/fuel-colors';
import { cn } from '@/lib/utils';

/** A tank as any page has it: either with its fuel type nested, or with the fuel type's id/name flat. */
type TankLike = {
    id: number;
    name: string;
    fuel_type?: { id: number; name: string } | null;
    fuel_type_id?: number | null;
    fuel_type_name?: string | null;
};

function fuelTypeOf(tank: TankLike): { id: number; name: string } {
    return {
        id: tank.fuel_type?.id ?? tank.fuel_type_id ?? 0,
        name: tank.fuel_type?.name ?? tank.fuel_type_name ?? '',
    };
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
 * Each fuel type's dot is its own stored colour, the same everywhere in the app.
 */
export function TankSelectItems({ tanks }: { tanks: TankLike[] }) {
    const colors = useFuelColors();
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
                    <FuelTypeDot className={colors.byId(fuelTypeId).dot} />
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
                        <FuelTypeDot className={colors.byId(id).dot} />
                        {group.name}
                    </SelectLabel>
                    {items(id, group.tanks)}
                </SelectGroup>
            ))}
        </>
    );
}
