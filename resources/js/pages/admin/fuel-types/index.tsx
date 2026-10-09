import { Head, Link, router, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { RowActions } from '@/components/row-actions';
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
        router.delete(destroy.url(fuelType.id));
    }

    return (
        <>
            <Head title={t('fuel_types.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('fuel_types.title')}
                    description={t('fuel_types.description')}
                >
                    <Link
                        href={create()}
                        className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium"
                    >
                        {t('fuel_types.new')}
                    </Link>
                </PageHeader>

                <div className="table-stack overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">
                                    {t('common.name')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.slug')}
                                </th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {fuelTypes.map((fuelType) => (
                                <tr key={fuelType.id} className="border-t">
                                    <td className="px-4 py-3">
                                        {fuelType.name}
                                    </td>
                                    <td className="px-4 py-3">
                                        {fuelType.slug}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <RowActions
                                            edit={edit(fuelType.id).url}
                                            remove={() => remove(fuelType)}
                                        />
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
    breadcrumbs: [{ title: 'nav.fuel_types', href: index() }],
};
