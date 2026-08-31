import { Head, Link, router, usePage } from '@inertiajs/react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { create, destroy, edit, exportPdf, index } from '@/routes/transactions';
import type {
    Auth,
    Paginated,
    Transaction,
    TransactionType,
    UserSummary,
} from '@/types';

type PageProps = {
    auth: Auth;
    transactions: Paginated<Transaction>;
    users: UserSummary[];
    filters: { type?: string; user_id?: string };
};

export default function TransactionsIndex() {
    const { auth, transactions, users, filters } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const typeLabels: Record<TransactionType, string> = {
        fuel_sale: t('transactions.type.fuel_sale'),
        fuel_delivery: t('transactions.type.fuel_delivery'),
        other_income: t('transactions.type.other_income'),
        expense: t('transactions.type.expense'),
        purchase: t('transactions.type.purchase'),
        currency_exchange: t('transactions.type.currency_exchange'),
    };

    function applyFilter(key: 'type' | 'user_id', value: string) {
        router.get(
            index.url(),
            { ...filters, [key]: value === 'all' ? undefined : value },
            { preserveState: true, replace: true },
        );
    }

    function remove(transaction: Transaction) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroy.url(transaction.id));
        }
    }

    function detailFor(transaction: Transaction): string {
        if (
            transaction.type === 'fuel_sale' ||
            transaction.type === 'fuel_delivery'
        ) {
            const tankLabel =
                transaction.tank?.name ?? transaction.fuel_type?.name;

            return `${tankLabel} - ${formatNumber(transaction.liters ?? 0)} L`;
        }

        if (transaction.type === 'currency_exchange') {
            return `→ ${formatNumber(transaction.to_amount ?? 0)} ${transaction.to_currency ?? ''}`;
        }

        return transaction.description ?? '';
    }

    return (
        <>
            <Head title={t('transactions.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('transactions.title')}
                        description={t('transactions.description_label')}
                    />
                    <Link
                        href={create()}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                    >
                        {t('transactions.new')}
                    </Link>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Select
                        value={filters.type ?? 'all'}
                        onValueChange={(value) => applyFilter('type', value)}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder={t('common.all_types')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('common.all_types')}
                            </SelectItem>
                            {Object.entries(typeLabels).map(
                                ([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>

                    {auth.isAdmin && (
                        <Select
                            value={filters.user_id ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter('user_id', value)
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue
                                    placeholder={t('common.all_employees')}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    {t('common.all_employees')}
                                </SelectItem>
                                {users.map((user) => (
                                    <SelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

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
                                {auth.isAdmin && (
                                    <th className="px-4 py-2">
                                        {t('common.employee')}
                                    </th>
                                )}
                                <th className="px-4 py-2">
                                    {t('common.type')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('transactions.detail')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('common.amount')}
                                </th>
                                <th className="px-4 py-2">
                                    {t('transactions.debt_label')}
                                </th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.data.map((transaction) => (
                                <tr key={transaction.id} className="border-t">
                                    <td className="px-4 py-2">
                                        {formatDateTime(
                                            transaction.occurred_at,
                                        )}
                                    </td>
                                    {auth.isAdmin && (
                                        <td className="px-4 py-2">
                                            {transaction.user?.name}
                                        </td>
                                    )}
                                    <td className="px-4 py-2">
                                        {typeLabels[transaction.type]}
                                    </td>
                                    <td className="px-4 py-2">
                                        {detailFor(transaction)}
                                    </td>
                                    <td className="px-4 py-2">
                                        {formatNumber(transaction.amount)}{' '}
                                        {transaction.currency}
                                    </td>
                                    <td className="px-4 py-2">
                                        {transaction.debt && (
                                            <span className="text-xs">
                                                {t('transactions.debt_label')} —{' '}
                                                {transaction.debt.debtor?.name}
                                            </span>
                                        )}
                                    </td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        <Link
                                            href={edit(transaction.id)}
                                            className="text-sm underline"
                                        >
                                            {t('common.edit')}
                                        </Link>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(transaction)}
                                        >
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {transactions.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-4 py-6 text-center text-muted-foreground"
                                    >
                                        {t('common.no_results')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={transactions.links} />
            </div>
        </>
    );
}

TransactionsIndex.layout = {
    breadcrumbs: [{ title: 'Transactions', href: index() }],
};
