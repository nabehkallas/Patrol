import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { index, store } from '@/routes/admin/fuel-types';

export default function FuelTypeCreate() {
    const { t } = useTranslation();
    const form = useForm({ name: '', slug: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url());
    }

    return (
        <>
            <Head title={t('fuel_types.new')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('fuel_types.new')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">{t('common.slug')}</Label>
                        <Input
                            id="slug"
                            value={form.data.slug}
                            onChange={(e) => form.setData('slug', e.target.value)}
                            placeholder={t('fuel_types.slug_hint')}
                        />
                        <InputError message={form.errors.slug} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('fuel_types.create')}
                    </Button>
                </form>
            </div>
        </>
    );
}

FuelTypeCreate.layout = {
    breadcrumbs: [
        { title: 'Fuel types', href: index() },
        { title: 'New fuel type', href: '' },
    ],
};
