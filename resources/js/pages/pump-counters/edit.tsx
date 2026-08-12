import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/pump-counters';

type Reading = {
    id: number;
    pump_id: number;
    tank_id: number | null;
    date: string;
    reading_value: string;
    liters_sold: string | null;
    governmental_liters: string | null;
    return_liters: string | null;
    notes: string | null;
};

type PumpOption = {
    id: number;
    name: string;
    fuel_type_ids: number[];
};

type TankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
};

type PageProps = {
    reading: Reading;
    pumps: PumpOption[];
    tanks: TankOption[];
};

export default function PumpCounterReadingEdit() {
    const { reading, pumps, tanks } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        pump_id: String(reading.pump_id),
        tank_id: String(reading.tank_id ?? tanks[0]?.id ?? ''),
        date: reading.date,
        reading_value: reading.reading_value,
        governmental_liters: reading.governmental_liters ?? '',
        return_liters: reading.return_liters ?? '',
        notes: reading.notes ?? '',
    });

    const selectedPump = pumps.find(
        (pump) => String(pump.id) === form.data.pump_id,
    );

    const availableTanks = useMemo(
        () =>
            selectedPump && selectedPump.fuel_type_ids.length > 0
                ? tanks.filter((tank) =>
                      selectedPump.fuel_type_ids.includes(tank.fuel_type_id),
                  )
                : tanks,
        [tanks, selectedPump],
    );

    function handlePumpChange(pumpId: string) {
        const pump = pumps.find((p) => String(p.id) === pumpId);
        const nextTanks =
            pump && pump.fuel_type_ids.length > 0
                ? tanks.filter((tank) =>
                      pump.fuel_type_ids.includes(tank.fuel_type_id),
                  )
                : tanks;

        form.setData((data) => ({
            ...data,
            pump_id: pumpId,
            tank_id: nextTanks.some((tank) => String(tank.id) === data.tank_id)
                ? data.tank_id
                : String(nextTanks[0]?.id ?? ''),
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(reading.id));
    }

    return (
        <>
            <Head title={t('pump_counters.edit_reading')} />

            <div className="max-w-md space-y-6">
                <Heading
                    variant="small"
                    title={t('pump_counters.edit_reading')}
                />

                {reading.liters_sold !== null && (
                    <p className="text-sm text-muted-foreground">
                        {t('pump_counters.liters_sold')}:{' '}
                        <span className="font-medium">
                            {formatNumber(reading.liters_sold)} L
                        </span>
                    </p>
                )}

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="pump_id">
                            {t('pump_counters.pump')}
                        </Label>
                        <Select
                            value={form.data.pump_id}
                            onValueChange={handlePumpChange}
                        >
                            <SelectTrigger id="pump_id" className="w-full">
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
                        <Label htmlFor="tank_id">{t('common.tank')}</Label>
                        <Select
                            value={form.data.tank_id}
                            onValueChange={(value) =>
                                form.setData('tank_id', value)
                            }
                        >
                            <SelectTrigger id="tank_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {availableTanks.map((tank) => (
                                    <SelectItem
                                        key={tank.id}
                                        value={String(tank.id)}
                                    >
                                        {tank.fuel_type_name} — {tank.name}
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
                        </Label>
                        <Input
                            id="reading_value"
                            type="number"
                            step="1"
                            min="0"
                            value={form.data.reading_value}
                            onChange={(e) =>
                                form.setData('reading_value', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reading_value} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="governmental_liters">
                            {t('pump_counters.governmental_sale')}
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
                        <InputError message={form.errors.governmental_liters} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="return_liters">
                            {t('pump_counters.return_liters')}
                        </Label>
                        <Input
                            id="return_liters"
                            type="number"
                            step="0.001"
                            min="0"
                            value={form.data.return_liters}
                            onChange={(e) =>
                                form.setData('return_liters', e.target.value)
                            }
                        />
                        <InputError message={form.errors.return_liters} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notes">{t('common.notes')}</Label>
                        <Textarea
                            id="notes"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

PumpCounterReadingEdit.layout = {
    breadcrumbs: [
        { title: 'Pump counters', href: index() },
        { title: 'Edit reading', href: '' },
    ],
};
