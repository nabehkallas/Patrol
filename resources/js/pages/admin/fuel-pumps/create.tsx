import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { index, store } from '@/routes/admin/fuel-pumps';
import type { FuelType } from '@/types';

type PageProps = {
    fuelTypes: FuelType[];
};

export default function FuelPumpCreate() {
    const { fuelTypes } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({ name: '', fuel_type_ids: [] as number[] });

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
        form.post(store.url());
    }

    return (
        <>
            <Head title={t('fuel_pumps.new')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('fuel_pumps.new')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Pump 1"
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
                        {t('common.create')}
                    </Button>
                </form>
            </div>
        </>
    );
}

FuelPumpCreate.layout = {
    breadcrumbs: [
        { title: 'Fuel pumps', href: index() },
        { title: 'New pump', href: '' },
    ],
};
