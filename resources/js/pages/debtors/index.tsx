import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    create,
    destroy,
    edit,
    exportPdf,
    index,
    settleAll,
} from '@/routes/debtors';
import { index as debtsIndex } from '@/routes/debts';
import type { DebtorSummary, Paginated } from '@/types';

type Row = {
    debtor: DebtorSummary;
    indent: boolean;
    parentLabel?: string | null;
};

type PageProps = {
    debtors: Paginated<DebtorSummary>;
    filters: { search?: string };
};

export default function DebtorsIndex() {
    const { debtors, filters } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search ?? '');

    function applyFilter(updates: Partial<PageProps['filters']>) {
        router.get(
            index.url(),
            { ...filters, ...updates },
            { preserveState: true, replace: true },
        );
    }

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => {
            applyFilter({ search: search || undefined });
        }, 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    function remove(debtor: DebtorSummary) {
        if (confirm(`${t('common.confirm_delete')} (${debtor.name})`)) {
            router.delete(destroy.url(debtor.id));
        }
    }

    function settleAllDebts(debtor: DebtorSummary) {
        if (confirm(`${t('debtors.confirm_settle_all')} (${debtor.name})`)) {
            router.patch(settleAll.url(debtor.id));
        }
    }

    const rows: Row[] = filters.search
        ? debtors.data.map((debtor) => ({
              debtor,
              indent: false,
              parentLabel: debtor.parent_name,
          }))
        : debtors.data.flatMap((debtor) => [
              { debtor, indent: false },
              ...(debtor.children ?? []).map((child) => ({
                  debtor: child,
                  indent: true,
              })),
          ]);

    return (
        <>
            <Head title={t('debtors.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('debtors.title')}
                        description={t('debtors.description')}
                    />
                    <Link
                        href={create()}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        {t('debtors.new')}
                    </Link>
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('common.search_by_name')}
                        className="w-56"
                    />

                    <GeneratePdfButton
                        href={exportPdf.url({ query: filters })}
                    />
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">
                                    {t('common.name')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('debtors.phone')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('debtors.outstanding')}
                                </th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(({ debtor, indent, parentLabel }) => (
                                <tr key={debtor.id} className="border-t">
                                    <td
                                        className={cn(
                                            'px-4 py-2',
                                            indent && 'ps-8',
                                        )}
                                    >
                                        {debtor.name}
                                        {parentLabel && (
                                            <span className="ms-2 text-xs text-muted-foreground">
                                                ({t('debtors.part_of')}{' '}
                                                {parentLabel})
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        {debtor.phone ?? '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {formatSyp(debtor.outstanding_syp)}
                                    </td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        <Link
                                            href={`${debtsIndex.url()}?debtor_id=${debtor.id}`}
                                            className="text-sm underline"
                                        >
                                            {t('debtors.view_debts')}
                                        </Link>
                                        {debtor.outstanding_syp > 0 && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    settleAllDebts(debtor)
                                                }
                                            >
                                                {t('debtors.settle_all')}
                                            </Button>
                                        )}
                                        <Link
                                            href={edit(debtor.id)}
                                            className="text-sm underline"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(debtor)}
                                        >
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {debtors.data.length === 0 && (
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

                <PaginationLinks links={debtors.links} />
            </div>
        </>
    );
}

DebtorsIndex.layout = {
    breadcrumbs: [{ title: 'Debtors', href: index() }],
};
