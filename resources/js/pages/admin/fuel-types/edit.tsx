import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/admin/fuel-types';
import type { FuelType } from '@/types';

type PageProps = {
    fuelType: FuelType;
};

export default function FuelTypeEdit() {
    const { fuelType } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({ name: fuelType.name, slug: fuelType.slug });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(fuelType.id));
    }

    return (
        <>
            <Head title={t('fuel_types.edit_title')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('fuel_types.edit_title')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">{t('common.slug')}</Label>
                        <Input id="slug" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} required />
                        <InputError message={form.errors.slug} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

FuelTypeEdit.layout = {
    breadcrumbs: [
        { title: 'Fuel types', href: index() },
        { title: 'Edit', href: '' },
    ],
};
