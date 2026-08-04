import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/admin/tanks';
import type { FuelType, Tank } from '@/types';

type PageProps = {
    tank: Tank;
    fuelTypes: FuelType[];
};

export default function TankEdit() {
    const { tank, fuelTypes } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        fuel_type_id: String(tank.fuel_type_id),
        name: tank.name,
        capacity_liters: tank.capacity_liters,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(tank.id));
    }

    return (
        <>
            <Head title={t('tanks.edit')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('tanks.edit')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="fuel_type_id">{t('common.fuel_type')}</Label>
                        <Select value={form.data.fuel_type_id} onValueChange={(value) => form.setData('fuel_type_id', value)}>
                            <SelectTrigger id="fuel_type_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {fuelTypes.map((fuelType) => (
                                    <SelectItem key={fuelType.id} value={String(fuelType.id)}>
                                        {fuelType.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.fuel_type_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="capacity_liters">{t('tanks.capacity_liters')}</Label>
                        <Input
                            id="capacity_liters"
                            type="number"
                            step="0.001"
                            min="0"
                            value={form.data.capacity_liters}
                            onChange={(e) => form.setData('capacity_liters', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.capacity_liters} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

TankEdit.layout = {
    breadcrumbs: [
        { title: 'Tanks', href: index() },
        { title: 'Edit', href: '' },
    ],
};
