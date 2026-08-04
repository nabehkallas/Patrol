import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/pump-counters';

type Reading = {
    id: number;
    pump_id: number;
    date: string;
    reading_value: string;
    liters_sold: string | null;
    governmental_liters: string | null;
    return_liters: string | null;
    notes: string | null;
    pump_name: string;
    tank_name: string;
    fuel_type_name: string;
};

type PageProps = {
    reading: Reading;
};

export default function PumpCounterReadingEdit() {
    const { reading } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        date: reading.date,
        reading_value: reading.reading_value,
        governmental_liters: reading.governmental_liters ?? '',
        return_liters: reading.return_liters ?? '',
        notes: reading.notes ?? '',
    });

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

                <p className="text-sm text-muted-foreground">
                    {reading.pump_name} — {reading.tank_name} (
                    {reading.fuel_type_name})
                    {reading.liters_sold !== null && (
                        <span className="ms-2 font-medium">
                            · {t('pump_counters.liters_sold')}:{' '}
                            {formatNumber(reading.liters_sold)} L
                        </span>
                    )}
                </p>

                <form onSubmit={submit} className="space-y-6">
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
                            step="0.001"
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
