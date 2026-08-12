import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/admin/fuel-pumps';
import type { FuelType } from '@/types';

type PageProps = {
    pump: { id: number; name: string; fuel_type_ids: number[] };
    fuelTypes: FuelType[];
};

export default function FuelPumpEdit() {
    const { pump, fuelTypes } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        name: pump.name,
        fuel_type_ids: pump.fuel_type_ids,
    });

    function toggleFuelType(id: number, checked: boolean) {
        form.setData(
            'fuel_type_ids',
            checked
                ? [...form.data.fuel_type_ids, id]
                : form.data.fuel_type_ids.filter((ftId) => ftId !== id),
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(pump.id));
    }

    return (
        <>
            <Head title={t('fuel_pumps.edit')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('fuel_pumps.edit')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label>{t('common.fuel_types')}</Label>
                        <div className="space-y-2">
                            {fuelTypes.map((fuelType) => (
                                <div
                                    key={fuelType.id}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`fuel_type_${fuelType.id}`}
                                        checked={form.data.fuel_type_ids.includes(
                                            fuelType.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleFuelType(
                                                fuelType.id,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`fuel_type_${fuelType.id}`}
                                        className="font-normal"
                                    >
                                        {fuelType.name}
                                    </Label>
                                </div>
                            ))}
                        </div>
                        <InputError message={form.errors.fuel_type_ids} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

FuelPumpEdit.layout = {
    breadcrumbs: [
        { title: 'Fuel pumps', href: index() },
        { title: 'Edit pump', href: '' },
    ],
};
