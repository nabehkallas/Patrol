import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { CurrencyCard } from '@/components/currency-card';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { create, destroy, edit, exportPdf, index } from '@/routes/debts';
import { store as storePayment } from '@/routes/debts/payments';
import type { Debt, Debtor, DebtsSummary, Paginated } from '@/types';

type PageProps = {
    debts: Paginated<Debt>;
    debtors: Debtor[];
    filters: {
        status?: string;
        direction?: string;
        sort?: string;
        sort_dir?: string;
        debtor_id?: string;
    };
    totals: DebtsSummary;
};

export default function DebtsIndex() {
    const { debts, debtors, filters, totals } = usePage<PageProps>().props;
    const { t } = useTranslation();

    function applyFilter(updates: Partial<PageProps['filters']>) {
        router.get(
            index.url(),
            { ...filters, ...updates },
            { preserveState: true, replace: true },
        );
    }

    function toggleStatusSort() {
        const nextDir =
            filters.sort === 'status' && filters.sort_dir === 'asc'
                ? 'desc'
                : 'asc';
        applyFilter({ sort: 'status', sort_dir: nextDir });
    }

    function remove(debt: Debt) {
        if (confirm(`${t('common.confirm_delete')} (${debt.debtor?.name})`)) {
            router.delete(destroy.url(debt.id));
        }
    }

    function whatFor(debt: Debt): string {
        const fuelTypeName =
            debt.transaction?.fuel_type?.name ?? debt.fuel_type?.name;
        const liters = debt.liters ?? debt.transaction?.liters;

        if (fuelTypeName && liters) {
            return `${fuelTypeName} — ${formatNumber(liters)} L`;
        }

        return fuelTypeName ?? debt.details ?? '—';
    }

    const [paymentAmounts, setPaymentAmounts] = useState<
        Record<number, string>
    >({});
    const [paymentErrors, setPaymentErrors] = useState<Record<number, string>>(
        {},
    );

    function paymentAmountFor(debt: Debt): string {
        return paymentAmounts[debt.id] ?? String(debt.remaining_amount);
    }

    function submitPayment(event: FormEvent, debt: Debt) {
        event.preventDefault();
        router.post(
            storePayment.url(debt.id),
            { amount: paymentAmountFor(debt) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPaymentAmounts((prev) => {
                        const next = { ...prev };
                        delete next[debt.id];

                        return next;
                    });
                    setPaymentErrors((prev) => ({ ...prev, [debt.id]: '' }));
                },
                onError: (errors) =>
                    setPaymentErrors((prev) => ({
                        ...prev,
                        [debt.id]: errors.amount ?? '',
                    })),
            },
        );
    }

    return (
        <>
            <Head title={t('debts.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('debts.title')}
                        description={t('debts.description')}
                    />
                    <Link
                        href={create()}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        {t('debts.new')}
                    </Link>
                </div>

                <div className="flex flex-wrap gap-4 [&>*]:max-w-xs [&>*]:min-w-[12rem] [&>*]:flex-1">
                    <CurrencyCard
                        label={t('debts.total_unpaid')}
                        breakdown={totals.outstanding}
                    />
                    <CurrencyCard
                        label={t('debts.total_debts')}
                        breakdown={totals.total}
                    />
                    <CurrencyCard
                        label={t('debts.payable_unpaid')}
                        breakdown={totals.payable_outstanding}
                    />
                    <CurrencyCard
                        label={t('debts.payable_total')}
                        breakdown={totals.payable_total}
                    />
                </div>

                <div className="flex flex-wrap gap-4">
                    <Select
                        value={filters.direction ?? 'all'}
                        onValueChange={(value) =>
                            applyFilter({
                                direction: value === 'all' ? undefined : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('debts.all_directions')}
                            </SelectItem>
                            <SelectItem value="receivable">
                                {t('debts.direction.receivable')}
                            </SelectItem>
                            <SelectItem value="payable">
                                {t('debts.direction.payable')}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(value) =>
                            applyFilter({
                                status: value === 'all' ? undefined : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('common.status')}
                            </SelectItem>
                            <SelectItem value="outstanding">
                                {t('debts.status.outstanding')}
                            </SelectItem>
                            <SelectItem value="settled">
                                {t('debts.status.settled')}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.debtor_id ?? 'all'}
                        onValueChange={(value) =>
                            applyFilter({
                                debtor_id: value === 'all' ? undefined : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder={t('common.debtor')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('common.debtor')}
                            </SelectItem>
                            {debtors.map((debtor) => (
                                <SelectItem
                                    key={debtor.id}
                                    value={String(debtor.id)}
                                >
                                    {debtor.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <GeneratePdfButton
                        href={exportPdf.url({ query: filters })}
                    />
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">
                                    {t('common.date')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.debtor')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('debts.what_for')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.amount')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('debts.direction')}
                                </th>
                                <th className="px-4 py-2">
                                    <button
                                        type="button"
                                        onClick={toggleStatusSort}
                                        className="flex items-center gap-1 hover:underline"
                                    >
                                        {t('common.status')}
                                        {filters.sort === 'status' && (
                                            <span>
                                                {filters.sort_dir === 'asc'
                                                    ? '▲'
                                                    : '▼'}
                                            </span>
                                        )}
                                    </button>
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.recorded_by')}
                                </th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {debts.data.map((debt) => (
                                <tr key={debt.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {formatDate(debt.date)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {debt.debtor?.name}
                                    </td>
                                    <td className="px-4 py-2">
                                        {whatFor(debt)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {debt.paid_amount > 0 &&
                                        debt.status === 'outstanding' ? (
                                            <div>
                                                <div>
                                                    {formatNumber(
                                                        debt.remaining_amount,
                                                    )}{' '}
                                                    {debt.currency}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {t('debts.original_amount')}
                                                    :{' '}
                                                    {formatNumber(debt.amount)}{' '}
                                                    {debt.currency}
                                                </div>
                                            </div>
                                        ) : (
                                            <>
                                                {formatNumber(debt.amount)}{' '}
                                                {debt.currency}
                                            </>
                                        )}
                                    </td>
                                    <td className="px-4 py-2">
                                        {debt.direction === 'payable'
                                            ? t('debts.direction.payable')
                                            : t('debts.direction.receivable')}
                                    </td>
                                    <td className="px-4 py-2">
                                        {debt.status === 'outstanding'
                                            ? t('debts.status.outstanding')
                                            : t('debts.status.settled')}
                                    </td>
                                    <td className="px-4 py-2">
                                        {debt.recorded_by?.name}
                                    </td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        {debt.status === 'outstanding' && (
                                            <form
                                                onSubmit={(event) =>
                                                    submitPayment(event, debt)
                                                }
                                                className="mb-1 inline-flex items-start gap-1"
                                            >
                                                <div>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        max={
                                                            debt.remaining_amount
                                                        }
                                                        value={paymentAmountFor(
                                                            debt,
                                                        )}
                                                        onChange={(e) =>
                                                            setPaymentAmounts(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    [debt.id]:
                                                                        e.target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                        className="h-8 w-24"
                                                    />
                                                    <InputError
                                                        message={
                                                            paymentErrors[
                                                                debt.id
                                                            ]
                                                        }
                                                    />
                                                </div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    {t('debts.pay')}
                                                </Button>
                                            </form>
                                        )}
                                        <Link
                                            href={edit(debt.id)}
                                            className="text-sm underline"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(debt)}
                                        >
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {debts.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        {t('common.no_results')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={debts.links} />
            </div>
        </>
    );
}

DebtsIndex.layout = {
    breadcrumbs: [{ title: 'Debts', href: index() }],
};
