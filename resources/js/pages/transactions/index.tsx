import { Head, Link, router, usePage } from '@inertiajs/react';
import { OriginalPaymentNote } from '@/components/cash-source-fields';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import { PageHeader } from '@/components/page-header';
import PaginationLinks from '@/components/pagination-links';
import { RowActions } from '@/components/row-actions';
import { SectionToolbar } from '@/components/section-toolbar';
import { ShownTime } from '@/components/shown-time';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatNumber, formatCurrencyAmount } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as pumpCounters } from '@/routes/pump-counters';
import { index as sadcop } from '@/routes/sadcop';
import { index as shop } from '@/routes/shop';
import {
    create,
    destroy,
    edit,
    exportPdf,
    exportXlsx,
    index,
} from '@/routes/transactions';
import type {
    Auth,
    Paginated,
    Transaction,
    TransactionType,
    UserSummary,
} from '@/types';

/**
 * Every row is the same height: cells stay on one line, and the row is tall enough for the two
 * a cell can have (a late fuel sale's "entered" line, a payment's original amount) or the edit
 * and delete buttons.
 */
const ROW = 'h-[3.75rem] border-t';
const CELL = 'whitespace-nowrap px-3 py-3';

type PageProps = {
    auth: Auth;
    transactions: Paginated<
        Transaction & { managed_by: 'pump_counters' | 'shop' | 'sadcop' | null }
    >;
    users: UserSummary[];
    filters: { type?: string; user_id?: string; from: string; to: string };
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

    function applyFilter(changes: Partial<PageProps['filters']>) {
        router.get(
            index.url(),
            { ...filters, ...changes },
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
                <PageHeader
                    title={t('transactions.title')}
                    description={t('transactions.description_label')}
                >
                    <Link
                        href={create()}
                        className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium"
                    >
                        {t('transactions.new')}
                    </Link>
                </PageHeader>

                <SectionToolbar
                    title={t('transactions.log')}
                    children={
                        <DateRangePicker
                            from={filters.from}
                            to={filters.to}
                            onChange={(range) => applyFilter(range)}
                        />
                    }
                    actions={
                        <>
                            <GeneratePdfButton
                                href={exportPdf.url({ query: filters })}
                            />
                            <GenerateXlsxButton
                                href={exportXlsx.url({ query: filters })}
                            />
                        </>
                    }
                    filters={
                        <>
                            <Select
                                value={filters.type ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter({
                                        type:
                                            value === 'all' ? undefined : value,
                                    })
                                }
                            >
                                <SelectTrigger className="min-w-44">
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
                                        applyFilter({
                                            user_id:
                                                value === 'all'
                                                    ? undefined
                                                    : value,
                                        })
                                    }
                                >
                                    <SelectTrigger className="min-w-44">
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
                                <th className="px-3 py-3">
                                    {t('common.date')}
                                </th>
                                <th className="px-3 py-3">
                                    {t('common.type')}
                                </th>
                                <th className="px-3 py-3">
                                    {t('transactions.detail')}
                                </th>
                                <th className="px-3 py-3">
                                    {t('common.amount')}
                                </th>
                                <th className="min-w-0! px-3 py-3">
                                    {t('transactions.debt_label')}
                                </th>
                                {auth.isAdmin && (
                                    <th className="px-3 py-3">
                                        {t('common.employee')}
                                    </th>
                                )}
                                {auth.isAdmin && (
                                    <th className="px-3 py-3"></th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.data.map((transaction) => (
                                <tr key={transaction.id} className={ROW}>
                                    <td className={CELL}>
                                        <ShownTime
                                            {...(transaction.shown ?? {
                                                at: transaction.occurred_at,
                                                entered: null,
                                            })}
                                        />
                                    </td>
                                    <td className={CELL}>
                                        {typeLabels[transaction.type]}
                                    </td>
                                    <td className={CELL}>
                                        {/* A long description is cut, in full on hover. */}
                                        <span
                                            className="block max-w-64 truncate"
                                            title={detailFor(transaction)}
                                        >
                                            {detailFor(transaction)}
                                        </span>
                                    </td>
                                    <td className={CELL}>
                                        {formatCurrencyAmount(
                                            transaction.amount,
                                            transaction.currency,
                                        )}
                                        <OriginalPaymentNote
                                            original={
                                                transaction.original_currency
                                                    ? {
                                                          amount:
                                                              transaction.original_amount ??
                                                              0,
                                                          currency:
                                                              transaction.original_currency,
                                                          rate:
                                                              transaction.original_rate ??
                                                              0,
                                                      }
                                                    : null
                                            }
                                        />
                                    </td>
                                    <td className={CELL}>
                                        {transaction.debt && (
                                            <span
                                                className="block max-w-40 truncate text-xs"
                                                title={
                                                    transaction.debt.debtor
                                                        ?.name
                                                }
                                            >
                                                {t('transactions.debt_label')} —{' '}
                                                {transaction.debt.debtor?.name}
                                            </span>
                                        )}
                                    </td>
                                    {auth.isAdmin && (
                                        <td className={CELL}>
                                            <span
                                                className="block max-w-32 truncate"
                                                title={transaction.user?.name}
                                            >
                                                {transaction.user?.name}
                                            </span>
                                        </td>
                                    )}
                                    {auth.isAdmin && (
                                        <td className={cn(CELL, 'text-end')}>
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
    breadcrumbs: [{ title: 'nav.transactions', href: index() }],
};
