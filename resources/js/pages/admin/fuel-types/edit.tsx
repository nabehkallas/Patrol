import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FuelColorPicker } from '@/components/fuel-color-picker';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
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

    const { fuelTypeColors } = usePage<{
        fuelTypeColors: { id: number; color: string | null }[];
    }>().props;
    const form = useForm({
        name: fuelType.name,
        slug: fuelType.slug,
        color: fuelType.color ?? '',
    });
    const usedByOthers = fuelTypeColors
        .filter((other) => other.id !== fuelType.id)
        .map((other) => other.color)
        .filter((color): color is string => color !== null);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(fuelType.id));
    }

    return (
        <>
            <Head title={t('fuel_types.edit_title')} />

            <div className="max-w-md space-y-6">
                <PageHeader title={t('fuel_types.edit_title')} />

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
                        <Label htmlFor="slug">{t('common.slug')}</Label>
                        <Input
                            id="slug"
                            value={form.data.slug}
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.slug} />
                    </div>

                    <div className="grid gap-2">
                        <Label>{t('fuel_types.color')}</Label>
                        <FuelColorPicker
                            value={form.data.color}
                            onChange={(color) => form.setData('color', color)}
                            usedByOthers={usedByOthers}
                        />
                        <p className="text-muted-foreground text-xs">
                            {t('fuel_types.color_hint')}
                        </p>
                        <InputError message={form.errors.color} />
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
        { title: 'nav.fuel_types', href: index() },
        { title: 'common.edit', href: '' },
    ],
};
