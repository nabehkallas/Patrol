import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDefaultEntryDate } from '@/hooks/use-default-entry-date';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { calculateTankVolume } from '@/lib/tank-volume';
import { store as storeReading } from '@/routes/inventory';
import { tankVolume } from '@/routes/tools';

type TankOption = {
    id: number;
    name: string;
    fuel_type: string;
    capacity_liters: number;
};

const CUSTOM = 'custom';

export default function TankVolume() {
    const { tanks } = usePage<{ tanks: TankOption[] }>().props;
    const { t } = useTranslation();
    const defaultEntryDate = useDefaultEntryDate();
    const [tankId, setTankId] = useState(CUSTOM);

    // Picking one of the station's tanks fills in its capacity; the reading can then be saved
    // as that tank's actual measurement for the day, straight into Inventory.
    function pickTank(value: string) {
        setTankId(value);
        const tank = tanks.find((option) => String(option.id) === value);

        if (tank) {
            setCapacity(String(tank.capacity_liters));
        }
    }

    function saveReading() {
        if (!result || tankId === CUSTOM) {
            return;
        }

        router.post(storeReading.url(), {
            tank_id: Number(tankId),
            date: defaultEntryDate,
            quantity_liters: Math.round(result.volume),
            notes: t('tank_volume.saved_note'),
        });
    }

    const [capacity, setCapacity] = useState('');
    const [diameter, setDiameter] = useState('');
    const [height, setHeight] = useState('');

    const heightExceedsDiameter = useMemo(() => {
        const d = Number(diameter);
        const h = Number(height);

        return Number.isFinite(d) && Number.isFinite(h) && d > 0 && h > d;
    }, [diameter, height]);

    const result = calculateTankVolume(
        Number(capacity),
        Number(diameter),
        Number(height),
    );

    return (
        <>
            <Head title={t('tank_volume.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('tank_volume.title')}
                    description={t('tank_volume.description')}
                />

                <div className="grid items-start gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('tank_volume.title')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {tanks.length > 0 && (
                                <div className="grid gap-2">
                                    <Label htmlFor="tank">
                                        {t('common.tank')}
                                    </Label>
                                    <Select
                                        value={tankId}
                                        onValueChange={pickTank}
                                    >
                                        <SelectTrigger
                                            id="tank"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={CUSTOM}>
                                                {t('tank_volume.other_tank')}
                                            </SelectItem>
                                            {tanks.map((tank) => (
                                                <SelectItem
                                                    key={tank.id}
                                                    value={String(tank.id)}
                                                >
                                                    {tank.fuel_type} —{' '}
                                                    {tank.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="capacity">
                                    {t('tank_volume.capacity')}
                                </Label>
                                <Input
                                    id="capacity"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={capacity}
                                    onChange={(e) =>
                                        setCapacity(e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="diameter">
                                    {t('tank_volume.diameter')} (
                                    {t('tank_volume.unit_cm')})
                                </Label>
                                <Input
                                    id="diameter"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={diameter}
                                    onChange={(e) =>
                                        setDiameter(e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="height">
                                    {t('tank_volume.height')} (
                                    {t('tank_volume.unit_cm')})
                                </Label>
                                <Input
                                    id="height"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={height}
                                    onChange={(e) => setHeight(e.target.value)}
                                />
                                {heightExceedsDiameter && (
                                    <p className="text-destructive text-xs">
                                        {t('tank_volume.height_warning')}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>{t('tank_volume.result')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {result ? (
                                <>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            {t('tank_volume.fill_ratio')}
                                        </span>
                                        <span>
                                            {formatNumber(result.ratio)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            {t('tank_volume.coefficient')}
                                        </span>
                                        <span>
                                            {formatNumber(result.coefficient)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            {t('tank_volume.fill_percentage')}
                                        </span>
                                        <span>
                                            {formatNumber(
                                                result.coefficient * 100,
                                            )}
                                            %
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-t pt-2 text-base font-medium">
                                        <span>{t('tank_volume.volume')}</span>
                                        <span>
                                            {formatNumber(result.volume)} L
                                        </span>
                                    </div>
                                    {tankId !== CUSTOM && (
                                        <div className="space-y-1.5 pt-3">
                                            <Button
                                                className="w-full"
                                                onClick={saveReading}
                                            >
                                                {t('tank_volume.save_reading')}
                                            </Button>
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'tank_volume.save_reading_hint',
                                                )}
                                            </p>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <p className="text-muted-foreground">
                                    {t('tank_volume.enter_values')}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

TankVolume.layout = {
    breadcrumbs: [{ title: 'tank_volume.title', href: tankVolume() }],
};
