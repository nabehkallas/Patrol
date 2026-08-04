import { Head, Link, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { create, destroy, edit, index } from '@/routes/admin/tanks';
import type { Tank } from '@/types';

type PageProps = {
    tanks: Tank[];
};

export default function TanksIndex() {
    const { tanks } = usePage<PageProps>().props;
    const { t } = useTranslation();

    function remove(tank: Tank) {
        if (confirm(`${t('common.confirm_delete')} (${tank.name})`)) {
            router.delete(destroy.url(tank.id));
        }
    }

    return (
        <>
            <Head title={t('tanks.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('tanks.title')}
                        description={t('tanks.description')}
                    />
                    <Link
                        href={create()}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        {t('tanks.new')}
                    </Link>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">
                                    {t('common.fuel_type')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.name')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('tanks.capacity_liters')}
                                </th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {tanks.map((tank) => (
                                <tr key={tank.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {tank.fuel_type?.name}
                                    </td>
                                    <td className="px-4 py-2">{tank.name}</td>
                                    <td className="px-4 py-2">
                                        {formatNumber(tank.capacity_liters)}
                                    </td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        <Link
                                            href={edit(tank.id)}
                                            className="text-sm underline"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(tank)}
                                        >
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {tanks.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
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

TanksIndex.layout = {
    breadcrumbs: [{ title: 'Tanks', href: index() }],
};
