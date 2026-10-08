import { Head, Link, router, usePage } from '@inertiajs/react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import PaginationLinks from '@/components/pagination-links';
import { RowActions } from '@/components/row-actions';
import { SectionToolbar } from '@/components/section-toolbar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index as pumpCounters } from '@/routes/pump-counters';
import { index as sadcop } from '@/routes/sadcop';
import { index as shop } from '@/routes/shop';
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
    transactions: Paginated<
        Transaction & { managed_by: 'pump_counters' | 'shop' | 'sadcop' | null }
    >;
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

    // Rows another screen recorded are corrected on that screen, so their records stay in step.
    const managedScreens = {
        pump_counters: {
            label: t('nav.pump_counters'),
            href: pumpCounters.url(),
        },
        shop: { label: t('nav.shop'), href: shop.url() },
        sadcop: { label: t('nav.sadcop'), href: sadcop.url() },
    };

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
                        className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium"
                    >
                        {t('transactions.new')}
                    </Link>
                </div>

                <SectionToolbar
                    title={t('transactions.log')}
                    actions={
                        <GeneratePdfButton
                            href={exportPdf.url({ query: filters })}
                        />
                    }
                    filters={
                        <>
                            <Select
                                value={filters.type ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter('type', value)
                                }
                            >
                                <SelectTrigger className="w-44">
                                    <SelectValue
                                        placeholder={t('common.all_types')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        {t('common.all_types')}
                                    </SelectItem>
                                    {Object.entries(typeLabels).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
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
                                            placeholder={t(
                                                'common.all_employees',
                                            )}
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
                        </>
                    }
                />

                <div className="table-stack overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">
                                    {t('common.date')}
                                </th>
                                {auth.isAdmin && (
                                    <th className="px-4 py-3">
                                        {t('common.employee')}
                                    </th>
                                )}
                                <th className="px-4 py-3">
                                    {t('common.type')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('transactions.detail')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.amount')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('transactions.debt_label')}
                                </th>
                                {auth.isAdmin && (
                                    <th className="px-4 py-3"></th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.data.map((transaction) => (
                                <tr key={transaction.id} className="border-t">
                                    <td className="px-4 py-3">
                                        {formatDateTime(
                                            transaction.occurred_at,
                                        )}
                                    </td>
                                    {auth.isAdmin && (
                                        <td className="px-4 py-3">
                                            {transaction.user?.name}
                                        </td>
                                    )}
                                    <td className="px-4 py-3">
                                        {typeLabels[transaction.type]}
                                    </td>
                                    <td className="px-4 py-3">
                                        {detailFor(transaction)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {formatNumber(transaction.amount)}{' '}
                                        {transaction.currency}
                                    </td>
                                    <td className="px-4 py-3">
                                        {transaction.debt && (
                                            <span className="text-xs">
                                                {t('transactions.debt_label')} —{' '}
                                                {transaction.debt.debtor?.name}
                                            </span>
                                        )}
                                    </td>
                                    {auth.isAdmin && (
                                        <td className="px-4 py-3 text-end">
                                            <RowActions
                                                edit={edit.url(transaction.id)}
                                                remove={destroy.url(
                                                    transaction.id,
                                                )}
                                                managedIn={
                                                    transaction.managed_by
                                                        ? managedScreens[
                                                              transaction
                                                                  .managed_by
                                                          ]
                                                        : undefined
                                                }
                                            />
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {transactions.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="text-muted-foreground px-4 py-6 text-center"
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
