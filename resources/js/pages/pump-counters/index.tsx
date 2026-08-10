import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { destroy, edit, exportPdf, index, store } from '@/routes/pump-counters';
import type { Auth, PumpCounterReading, PumpSummary } from '@/types';

type TankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
};

type PageProps = {
    auth: Auth;
    pumps: PumpSummary[];
    tanks: TankOption[];
    readings: PumpCounterReading[];
    date: string;
};

export default function PumpCountersIndex() {
    const { auth, pumps, tanks, readings, date } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        pump_id: String(pumps[0]?.id ?? ''),
        tank_id: String(
            pumps[0]?.latest_reading?.tank_id ?? tanks[0]?.id ?? '',
        ),
        date: new Date().toISOString().slice(0, 10),
        reading_value: '',
        governmental_liters: '',
        return_liters: '',
        notes: '',
    });

    const selectedPump = pumps.find((p) => String(p.id) === form.data.pump_id);

    const availableTanks = useMemo(
        () =>
            selectedPump?.fuel_type_id
                ? tanks.filter(
                      (tank) => tank.fuel_type_id === selectedPump.fuel_type_id,
                  )
                : tanks,
        [tanks, selectedPump],
    );

    // Defaults to whichever tank was used for this pump's last reading — attendants almost
    // always feed the same pump from the same tank, so this saves a re-selection every time.
    function defaultTankFor(
        pump: PumpSummary | undefined,
        options: TankOption[],
    ) {
        const lastTankId = pump?.latest_reading?.tank_id;
        const lastTankStillValid =
            lastTankId !== null &&
            lastTankId !== undefined &&
            options.some((tank) => tank.id === lastTankId);

        return lastTankStillValid ? lastTankId : (options[0]?.id ?? '');
    }

    function handlePumpChange(pumpId: string) {
        const pump = pumps.find((p) => String(p.id) === pumpId);
        const nextTanks = pump?.fuel_type_id
            ? tanks.filter((tank) => tank.fuel_type_id === pump.fuel_type_id)
            : tanks;

        form.setData((data) => ({
            ...data,
            pump_id: pumpId,
            tank_id: String(defaultTankFor(pump, nextTanks)),
        }));
    }

    const pumpsByFuelType = useMemo(() => {
        const groups = new Map<string, PumpSummary[]>();

        for (const pump of pumps) {
            const key = pump.fuel_type_name ?? t('common.none');
            groups.set(key, [...(groups.get(key) ?? []), pump]);
        }

        return Array.from(groups.entries()).map(([fuelTypeName, group]) => ({
            fuelTypeName,
            pumps: group,
            dailyLitersSold: group.reduce(
                (total, pump) => total + pump.daily_liters_sold,
                0,
            ),
        }));
    }, [pumps, t]);

    const maxLitersSold =
        selectedPump?.latest_reading && form.data.reading_value !== ''
            ? Number(form.data.reading_value) -
              Number(selectedPump.latest_reading.reading_value)
            : null;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            onSuccess: () =>
                form.reset(
                    'reading_value',
                    'governmental_liters',
                    'return_liters',
                    'notes',
                ),
        });
    }

    function handleDateChange(newDate: string) {
        router.get(index(), { date: newDate }, { preserveScroll: false });
    }

    function removeReading(reading: PumpCounterReading) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroy.url(reading.id));
        }
    }

    return (
        <>
            <Head title={t('pump_counters.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('pump_counters.title')}
                    description={t('pump_counters.description')}
                />

                <div className="space-y-6">
                    {pumpsByFuelType.map((group) => (
                        <div key={group.fuelTypeName} className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h3 className="font-semibold">
                                    {group.fuelTypeName}
                                </h3>
                                <div className="text-sm">
                                    <span className="text-muted-foreground">
                                        {t(
                                            'pump_counters.fuel_type_daily_total',
                                        )}
                                        :{' '}
                                    </span>
                                    <span
                                        className={cn(
                                            'font-medium',
                                            group.dailyLitersSold > 0 &&
                                                'text-green-600 dark:text-green-400',
                                        )}
                                    >
                                        {formatNumber(group.dailyLitersSold)} L
                                    </span>
                                </div>
                            </div>
                            <div className="grid gap-4 md:grid-cols-3">
                                {group.pumps.map((pump) => (
                                    <Card key={pump.id}>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                {pump.name}
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-1 text-sm">
                                            <div className="flex justify-between font-medium">
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        'pump_counters.daily_total',
                                                    )}
                                                </span>
                                                <span
                                                    className={cn(
                                                        pump.daily_liters_sold >
                                                            0 &&
                                                            'text-green-600 dark:text-green-400',
                                                    )}
                                                >
                                                    {formatNumber(
                                                        pump.daily_liters_sold,
                                                    )}{' '}
                                                    L
                                                </span>
                                            </div>
                                            {pump.latest_reading ? (
                                                <div className="flex justify-between">
                                                    <span className="text-muted-foreground">
                                                        {t(
                                                            'pump_counters.reading_value',
                                                        )}
                                                    </span>
                                                    <span>
                                                        {formatNumber(
                                                            pump.latest_reading
                                                                .reading_value,
                                                            0,
                                                        )}
                                                    </span>
                                                </div>
                                            ) : (
                                                <p className="text-muted-foreground">
                                                    {t(
                                                        'pump_counters.no_previous',
                                                    )}
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('pump_counters.record')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="grid gap-4 md:grid-cols-4"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="pump_id">
                                    {t('pump_counters.pump')}
                                </Label>
                                <Select
                                    value={form.data.pump_id}
                                    onValueChange={handlePumpChange}
                                >
                                    <SelectTrigger
                                        id="pump_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {pumps.map((pump) => (
                                            <SelectItem
                                                key={pump.id}
                                                value={String(pump.id)}
                                            >
                                                {pump.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.pump_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tank_id">
                                    {t('common.tank')}
                                </Label>
                                <Select
                                    value={form.data.tank_id}
                                    onValueChange={(value) =>
                                        form.setData('tank_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="tank_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableTanks.map((tank) => (
                                            <SelectItem
                                                key={tank.id}
                                                value={String(tank.id)}
                                            >
                                                {tank.fuel_type_name} —{' '}
                                                {tank.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.tank_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="date">{t('common.date')}</Label>
                                <Input
                                    id="date"
                                    type="date"
                                    value={form.data.date}
                                    onChange={(e) =>
                                        form.setData('date', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.date} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="reading_value">
                                    {t('pump_counters.reading_value')}
                                    {selectedPump?.latest_reading && (
                                        <span className="ms-2 text-sm font-normal text-muted-foreground">
                                            ({t('pump_counters.previous')}:{' '}
                                            {formatNumber(
                                                selectedPump.latest_reading
                                                    .reading_value,
                                                0,
                                            )}
                                            )
                                        </span>
                                    )}
                                </Label>
                                <Input
                                    id="reading_value"
                                    type="number"
                                    step="1"
                                    min="0"
                                    value={form.data.reading_value}
                                    onChange={(e) =>
                                        form.setData(
                                            'reading_value',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.reading_value}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="governmental_liters">
                                    {t('pump_counters.governmental_sale')}
                                    {maxLitersSold !== null && (
                                        <span className="ms-2 text-xs font-normal text-muted-foreground">
                                            ({t('pump_counters.max')}:{' '}
                                            {formatNumber(maxLitersSold)} L)
                                        </span>
                                    )}
                                </Label>
                                <Input
                                    id="governmental_liters"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.data.governmental_liters}
                                    onChange={(e) =>
                                        form.setData(
                                            'governmental_liters',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.governmental_liters}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="return_liters">
                                    {t('pump_counters.return_liters')}
                                    {maxLitersSold !== null && (
                                        <span className="ms-2 text-xs font-normal text-muted-foreground">
                                            ({t('pump_counters.max')}:{' '}
                                            {formatNumber(maxLitersSold)} L)
                                        </span>
                                    )}
                                </Label>
                                <Input
                                    id="return_liters"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.data.return_liters}
                                    onChange={(e) =>
                                        form.setData(
                                            'return_liters',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.return_liters}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">
                                    {t('common.notes')}
                                </Label>
                                <Textarea
                                    id="notes"
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                            </div>

                            <Button
                                type="submit"
                                disabled={form.processing}
                                className="md:col-span-4 md:w-fit"
                            >
                                {t('pump_counters.save')}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <div className="space-y-3">
                    <div className="flex items-center gap-4">
                        <h3 className="font-semibold">
                            {t('pump_counters.history')}
                        </h3>
                        <Input
                            type="date"
                            value={date}
                            onChange={(e) => handleDateChange(e.target.value)}
                            className="w-auto"
                        />
                        <GeneratePdfButton
                            href={exportPdf.url({ query: { date } })}
                        />
                    </div>

                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-start">
                                    <th className="px-4 py-2">
                                        {t('pump_counters.time')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('pump_counters.pump')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('common.tank')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('pump_counters.reading_value')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('pump_counters.liters_sold')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('pump_counters.governmental_sale')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('pump_counters.return_liters')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('common.recorded_by')}
                                    </th>
                                    <th className="px-4 py-2">
                                        {t('common.notes')}
                                    </th>
                                    {auth.isAdmin && (
                                        <th className="px-4 py-2"></th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {readings.map((reading) => (
                                    <tr key={reading.id} className="border-t">
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            {formatDateTime(reading.created_at)}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.pump?.name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.tank
                                                ? `${reading.tank.fuel_type?.name} — ${reading.tank.name}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {formatNumber(
                                                reading.reading_value,
                                                0,
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.liters_sold !== null
                                                ? `${formatNumber(reading.liters_sold)} L`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.governmental_liters !==
                                            null
                                                ? `${formatNumber(reading.governmental_liters)} L`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.return_liters !== null
                                                ? `${formatNumber(reading.return_liters)} L`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.recorded_by?.name}
                                        </td>
                                        <td className="px-4 py-2">
                                            {reading.notes}
                                        </td>
                                        {auth.isAdmin && (
                                            <td className="space-x-2 px-4 py-2 text-end">
                                                <Link
                                                    href={edit(reading.id)}
                                                    className="text-sm underline"
                                                >
                                                    {t('common.edit')}
                                                </Link>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeReading(reading)
                                                    }
                                                >
                                                    {t('common.delete')}
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                                {readings.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={auth.isAdmin ? 10 : 9}
                                            className="px-4 py-6 text-center text-muted-foreground"
                                        >
                                            {t('common.no_results')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </>
    );
}

PumpCountersIndex.layout = {
    breadcrumbs: [{ title: 'Pump counters', href: index() }],
};
