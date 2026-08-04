import { Head } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { calculateTankVolume } from '@/lib/tank-volume';
import { tankVolume } from '@/routes/tools';

export default function TankVolume() {
    const { t } = useTranslation();

    const [capacity, setCapacity] = useState('');
    const [diameter, setDiameter] = useState('');
    const [height, setHeight] = useState('');

    const heightExceedsDiameter = useMemo(() => {
        const d = Number(diameter);
        const h = Number(height);

        return Number.isFinite(d) && Number.isFinite(h) && d > 0 && h > d;
    }, [diameter, height]);

    const result = useMemo(
        () =>
            calculateTankVolume(
                Number(capacity),
                Number(diameter),
                Number(height),
            ),
        [capacity, diameter, height],
    );

    return (
        <>
            <Head title={t('tank_volume.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('tank_volume.title')}
                    description={t('tank_volume.description')}
                />

                <div className="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('tank_volume.title')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
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
                                    {t('tank_volume.diameter')}
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
                                    {t('tank_volume.height')}
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
                                    <p className="text-xs text-destructive">
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
