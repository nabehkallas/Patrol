import { Head, Link, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { create, destroy, edit, index } from '@/routes/admin/fuel-pumps';
import type { FuelPump } from '@/types';

type PageProps = {
    pumps: FuelPump[];
};

export default function FuelPumpsIndex() {
    const { pumps } = usePage<PageProps>().props;
    const { t } = useTranslation();

    function remove(pump: FuelPump) {
        if (confirm(`${t('common.confirm_delete')} (${pump.name})`)) {
            router.delete(destroy.url(pump.id));
        }
    }

    return (
        <>
            <Head title={t('fuel_pumps.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('fuel_pumps.title')}
                        description={t('fuel_pumps.description')}
                    />
                    <Link
                        href={create()}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        {t('fuel_pumps.new')}
                    </Link>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">
                                    {t('common.name')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.fuel_type')}
                                </th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {pumps.map((pump) => (
                                <tr key={pump.id} className="border-t">
                                    <td className="px-4 py-2 font-medium">
                                        {pump.name}
                                    </td>
                                    <td className="px-4 py-2">
                                        {pump.fuel_type?.name ?? '—'}
                                    </td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        <Link
                                            href={edit(pump.id)}
                                            className="text-sm underline"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(pump)}
                                        >
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {pumps.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        {t('common.no_results')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

FuelPumpsIndex.layout = {
    breadcrumbs: [{ title: 'Fuel pumps', href: index() }],
};
