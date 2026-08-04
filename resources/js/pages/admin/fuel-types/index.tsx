import { Head, Link, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { create, destroy, edit, index } from '@/routes/admin/fuel-types';
import type { FuelType } from '@/types';

type PageProps = {
    fuelTypes: FuelType[];
};

export default function FuelTypesIndex() {
    const { fuelTypes } = usePage<PageProps>().props;
    const { t } = useTranslation();

    function remove(fuelType: FuelType) {
        if (confirm(`${t('common.confirm_delete')} (${fuelType.name})`)) {
            router.delete(destroy.url(fuelType.id));
        }
    }

    return (
        <>
            <Head title={t('fuel_types.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading variant="small" title={t('fuel_types.title')} description={t('fuel_types.description')} />
                    <Link href={create()} className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium">
                        {t('fuel_types.new')}
                    </Link>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">{t('common.name')}</th>
                                <th className="px-4 py-2">{t('common.slug')}</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {fuelTypes.map((fuelType) => (
                                <tr key={fuelType.id} className="border-t">
                                    <td className="px-4 py-2">{fuelType.name}</td>
                                    <td className="px-4 py-2">{fuelType.slug}</td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        <Link href={edit(fuelType.id)} className="text-sm underline">
                                            {t('common.edit')}
                                        </Link>
                                        <Button variant="ghost" size="sm" onClick={() => remove(fuelType)}>
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

FuelTypesIndex.layout = {
    breadcrumbs: [{ title: 'Fuel types', href: index() }],
};
