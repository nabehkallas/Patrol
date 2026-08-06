import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatNumber, formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { exportPdf, index } from '@/routes/sadcop';
import { create as createDelivery } from '@/routes/sadcop/deliveries';
import { create as createDeposit } from '@/routes/sadcop/deposits';
import { destroy, edit } from '@/routes/sadcop/entries';
import { store as storeOpeningBalance } from '@/routes/sadcop/opening-balance';
import type {
    Auth,
    Paginated,
    SadcopLedgerEntry,
    SadcopLedgerEntryType,
} from '@/types';

type PageProps = {
    auth: Auth;
    entries: Paginated<SadcopLedgerEntry>;
    filters: { type?: string; from: string; to: string };
    balance: number;
    monthPayments: number;
    needsOpeningBalance: boolean;
};

export default function SadcopIndex() {
    const {
        auth,
        entries,
        filters,
        balance,
        monthPayments,
        needsOpeningBalance,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const typeLabels: Record<SadcopLedgerEntryType, string> = {
        opening: t('sadcop.type.opening'),
        deposit: t('sadcop.type.deposit'),
        delivery: t('sadcop.type.delivery'),
    };

    const openingForm = useForm({ amount: '' });

    function submitOpeningBalance(event: FormEvent) {
        event.preventDefault();
        openingForm.post(storeOpeningBalance.url());
    }

    function applyFilter(
        updates: Partial<{ type: string; from: string; to: string }>,
    ) {
        router.get(
            index.url(),
            { ...filters, ...updates },
            { preserveState: true, replace: true },
        );
    }

    function remove(entry: SadcopLedgerEntry) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroy.url(entry.id));
        }
    }

    if (needsOpeningBalance) {
        return (
            <>
                <Head title={t('sadcop.title')} />

                <div className="max-w-xl space-y-6">
                    <Heading
                        variant="small"
                        title={t('sadcop.title')}
                        description={t('sadcop.opening_balance_description')}
                    />

                    {auth.isAdmin ? (
                        <form
                            onSubmit={submitOpeningBalance}
                            className="space-y-6"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="amount">
                                    {t('sadcop.opening_balance')}
                                </Label>
                                <MoneyInput
                                    id="amount"
                                    value={openingForm.data.amount}
                                    onChange={(value) =>
                                        openingForm.setData('amount', value)
                                    }
                                    required
                                />
                                <InputError
                                    message={openingForm.errors.amount}
                                />
                            </div>

                            <Button
                                type="submit"
                                disabled={openingForm.processing}
                            >
                                {t('sadcop.save_opening_balance')}
                            </Button>
                        </form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('sadcop.ask_admin_opening_balance')}
                        </p>
                    )}
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('sadcop.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('sadcop.title')}
                        description={t('sadcop.description')}
                    />
                    <div className="flex gap-2">
                        {auth.isAdmin && (
                            <Link
                                href={createDeposit()}
                                className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                            >
                                {t('sadcop.transfer_money')}
                            </Link>
                        )}
                        <Link
                            href={createDelivery()}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                        >
                            {t('sadcop.record_delivery')}
                        </Link>
                    </div>
                </div>

                <div className="flex flex-wrap gap-4">
                    <Card className="max-w-xs min-w-[12rem] flex-1">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                {t('sadcop.balance')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {formatSyp(balance)}
                        </CardContent>
                    </Card>
                    <Card className="max-w-xs min-w-[12rem] flex-1">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                {t('sadcop.payments_this_month')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {formatSyp(monthPayments)}
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap items-end gap-4">
                    <div className="grid gap-1">
                        <Label htmlFor="from">{t('statistics.from')}</Label>
                        <Input
                            id="from"
                            type="date"
                            value={filters.from}
                            onChange={(e) =>
                                applyFilter({ from: e.target.value })
                            }
                            className="w-44"
                        />
                    </div>

                    <div className="grid gap-1">
                        <Label htmlFor="to">{t('statistics.to')}</Label>
                        <Input
                            id="to"
                            type="date"
                            value={filters.to}
                            onChange={(e) =>
                                applyFilter({ to: e.target.value })
                            }
                            className="w-44"
                        />
                    </div>

                    <Select
                        value={filters.type ?? 'all'}
                        onValueChange={(value) =>
                            applyFilter({
                                type: value === 'all' ? undefined : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder={t('common.all_types')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('common.all_types')}
                            </SelectItem>
                            <SelectItem value="opening">
                                {t('sadcop.type.opening')}
                            </SelectItem>
                            <SelectItem value="deposit">
                                {t('sadcop.type.deposit')}
                            </SelectItem>
                            <SelectItem value="delivery">
                                {t('sadcop.type.delivery')}
                            </SelectItem>
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
                                    {t('common.type')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.liters')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('sadcop.cost_price_per_liter')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.amount')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.recorded_by')}
                                </th>
                                {auth.isAdmin && (
                                    <th className="px-4 py-2"></th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.map((entry) => (
                                <tr key={entry.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {formatDateTime(entry.occurred_at)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {typeLabels[entry.type]}
                                    </td>
                                    <td className="px-4 py-2">
                                        {entry.liters !== null
                                            ? formatNumber(entry.liters)
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {entry.price_per_liter !== null
                                            ? formatNumber(
                                                  entry.price_per_liter,
                                              )
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-2">
                                        {entry.type === 'delivery' ? '-' : '+'}
                                        {formatSyp(Number(entry.amount))}
                                    </td>
                                    <td className="px-4 py-2">
                                        {entry.recorded_by?.name}
                                    </td>
                                    {auth.isAdmin && (
                                        <td className="space-x-2 px-4 py-2 text-end">
                                            <Link
                                                href={edit(entry.id)}
                                                className="text-sm underline"
                                            >
                                                {t('common.edit')}
                                            </Link>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => remove(entry)}
                                            >
                                                {t('common.delete')}
                                            </Button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {entries.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={auth.isAdmin ? 7 : 6}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        {t('common.no_results')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={entries.links} />
            </div>
        </>
    );
}

SadcopIndex.layout = {
    breadcrumbs: [{ title: 'Sadcop', href: index() }],
};
