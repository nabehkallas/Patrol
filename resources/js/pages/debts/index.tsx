import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { CurrencyCard } from '@/components/currency-card';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBreakdown, formatDate, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import {
    create,
    destroy,
    edit,
    exportPdf,
    index,
    settle,
    settleFiltered,
    transfer,
} from '@/routes/debts';
import { store as storePayment } from '@/routes/debts/payments';
import { preview as settleFilteredPreview } from '@/routes/debts/settle-filtered';
import type {
    Auth,
    CurrencyBreakdown,
    Debt,
    Debtor,
    DebtsSummary,
    Paginated,
} from '@/types';

type PageProps = {
    auth: Auth;
    debts: Paginated<Debt>;
    debtors: Debtor[];
    filters: {
        search?: string;
        status?: string;
        direction?: string;
        sort?: string;
        sort_dir?: string;
        debtor_id?: string;
    };
    totals: DebtsSummary;
};

export default function DebtsIndex() {
    const { auth, debts, debtors, filters, totals } =
        usePage<PageProps>().props;
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

    function settleDebt(debt: Debt) {
        if (confirm(t('debts.settle_confirm'))) {
            router.patch(settle.url(debt.id), {}, { preserveScroll: true });
        }
    }

    const [partialDebt, setPartialDebt] = useState<Debt | null>(null);
    const partialForm = useForm({ amount: '' });

    function openPartial(debt: Debt) {
        setPartialDebt(debt);
        partialForm.setData('amount', String(debt.remaining_amount));
    }

    function submitPartial(event: FormEvent) {
        event.preventDefault();

        if (!partialDebt) {
            return;
        }

        partialForm.post(storePayment.url(partialDebt.id), {
            preserveScroll: true,
            onSuccess: () => setPartialDebt(null),
        });
    }

    const [showSettleFiltered, setShowSettleFiltered] = useState(false);
    const today = new Date().toISOString().slice(0, 10);
    const settleFilteredForm = useForm({
        from: today,
        to: today,
    });

    const [settlePreview, setSettlePreview] = useState<{
        count: number;
        breakdown: CurrencyBreakdown;
    } | null>(null);
    const [settlePreviewLoading, setSettlePreviewLoading] = useState(false);

    useEffect(() => {
        if (!showSettleFiltered) {
            return;
        }

        const timeout = setTimeout(() => {
            setSettlePreviewLoading(true);
            fetch(
                settleFilteredPreview.url({
                    query: {
                        ...filters,
                        from: settleFilteredForm.data.from,
                        to: settleFilteredForm.data.to,
                    },
                }),
                { headers: { Accept: 'application/json' } },
            )
                .then((response) => response.json())
                .then(setSettlePreview)
                .finally(() => setSettlePreviewLoading(false));
        }, 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        showSettleFiltered,
        settleFilteredForm.data.from,
        settleFilteredForm.data.to,
    ]);

    function submitSettleFiltered(event: FormEvent) {
        event.preventDefault();
        settleFilteredForm.patch(settleFiltered.url({ query: filters }), {
            preserveScroll: true,
            onSuccess: () => setShowSettleFiltered(false),
        });
    }

    const [transferDebt, setTransferDebt] = useState<Debt | null>(null);
    const transferForm = useForm({ debtor_id: '' });

    function openTransfer(debt: Debt) {
        setTransferDebt(debt);
        transferForm.setData('debtor_id', String(debt.debtor_id));
    }

    function submitTransfer(event: FormEvent) {
        event.preventDefault();

        if (!transferDebt) {
            return;
        }

        transferForm.patch(transfer.url(transferDebt.id), {
            preserveScroll: true,
            onSuccess: () => setTransferDebt(null),
        });
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
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('common.search_by_name')}
                        className="w-56"
                    />

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

                {auth.isAdmin && (
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setSettlePreview(null);
                                setShowSettleFiltered(true);
                            }}
                        >
                            {t('debts.settle_filtered')}
                        </Button>
                    </div>
                )}

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
                                        {debt.status === 'outstanding' &&
                                            auth.isAdmin && (
                                                <>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            settleDebt(debt)
                                                        }
                                                    >
                                                        {t('debts.settle')}
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openPartial(debt)
                                                        }
                                                    >
                                                        {t('debts.partial')}
                                                    </Button>
                                                </>
                                            )}
                                        {auth.isAdmin && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    openTransfer(debt)
                                                }
                                            >
                                                {t('debts.transfer')}
                                            </Button>
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

            <Dialog
                open={transferDebt !== null}
                onOpenChange={(open) => !open && setTransferDebt(null)}
            >
                <DialogContent>
                    <DialogTitle>{t('debts.transfer_title')}</DialogTitle>

                    <form onSubmit={submitTransfer} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="transfer_debtor_id">
                                {t('common.debtor')}
                            </Label>
                            <Select
                                value={transferForm.data.debtor_id}
                                onValueChange={(value) =>
                                    transferForm.setData('debtor_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="transfer_debtor_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
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
                            <InputError
                                message={transferForm.errors.debtor_id}
                            />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={transferForm.processing}
                            >
                                {t('debts.transfer')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={partialDebt !== null}
                onOpenChange={(open) => !open && setPartialDebt(null)}
            >
                <DialogContent>
                    <DialogTitle>{t('debts.partial_title')}</DialogTitle>

                    <form onSubmit={submitPartial} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="partial_amount">
                                {t('common.amount')}
                            </Label>
                            <Input
                                id="partial_amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                max={partialDebt?.remaining_amount}
                                value={partialForm.data.amount}
                                onChange={(e) =>
                                    partialForm.setData(
                                        'amount',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={partialForm.errors.amount} />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={partialForm.processing}
                            >
                                {t('debts.pay')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={showSettleFiltered}
                onOpenChange={setShowSettleFiltered}
            >
                <DialogContent>
                    <DialogTitle>{t('debts.settle_filtered')}</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {t('debts.settle_filtered_description')}
                    </p>

                    <form onSubmit={submitSettleFiltered} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="settle_filtered_from">
                                    {t('statistics.from')}
                                </Label>
                                <Input
                                    id="settle_filtered_from"
                                    type="date"
                                    value={settleFilteredForm.data.from}
                                    onChange={(e) =>
                                        settleFilteredForm.setData(
                                            'from',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={settleFilteredForm.errors.from}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="settle_filtered_to">
                                    {t('statistics.to')}
                                </Label>
                                <Input
                                    id="settle_filtered_to"
                                    type="date"
                                    value={settleFilteredForm.data.to}
                                    onChange={(e) =>
                                        settleFilteredForm.setData(
                                            'to',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={settleFilteredForm.errors.to}
                                />
                            </div>
                        </div>

                        <div className="rounded-lg border px-4 py-3 text-sm">
                            <div className="text-muted-foreground">
                                {t('debts.settle_filtered_total')}
                            </div>
                            <div className="font-medium">
                                {settlePreviewLoading
                                    ? t('common.loading')
                                    : settlePreview
                                      ? `${formatBreakdown(settlePreview.breakdown)} (${settlePreview.count})`
                                      : '—'}
                            </div>
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={settleFilteredForm.processing}
                            >
                                {t('debts.settle_filtered')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

DebtsIndex.layout = {
    breadcrumbs: [{ title: 'Debts', href: index() }],
};
