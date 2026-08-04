import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
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
import { useTranslation } from '@/lib/i18n';
import { index, store } from '@/routes/admin/fuel-pumps';
import type { FuelType } from '@/types';

type PageProps = {
    fuelTypes: FuelType[];
};

export default function FuelPumpCreate() {
    const { fuelTypes } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({ name: '', fuel_type_id: '' });

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
                        <Label htmlFor="fuel_type_id">
                            {t('common.fuel_type')}
                        </Label>
                        <Select
                            value={form.data.fuel_type_id || 'none'}
                            onValueChange={(value) =>
                                form.setData(
                                    'fuel_type_id',
                                    value === 'none' ? '' : value,
                                )
                            }
                        >
                            <SelectTrigger id="fuel_type_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    {t('common.none')}
                                </SelectItem>
                                {fuelTypes.map((fuelType) => (
                                    <SelectItem
                                        key={fuelType.id}
                                        value={String(fuelType.id)}
                                    >
                                        {fuelType.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.fuel_type_id} />
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
