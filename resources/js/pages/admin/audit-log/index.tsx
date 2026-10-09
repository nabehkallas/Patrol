import { Head, router, usePage } from '@inertiajs/react';
import { Fragment } from 'react';
import { PageHeader } from '@/components/page-header';
import PaginationLinks from '@/components/pagination-links';
import { SectionToolbar } from '@/components/section-toolbar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/hooks/use-locale';
import { formatDateTime } from '@/lib/format';
import type { TranslationKey } from '@/lib/i18n';
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/admin/audit-log';
import type { Paginated } from '@/types';

type AuditLog = {
    id: number;
    user_name: string | null;
    action: string;
    entity_type: string | null;
    entity_id: number | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
};

type PageProps = {
    logs: Paginated<AuditLog>;
    filters: { entity?: string; action?: string };
    entities: string[];
    actions: string[];
};

const ALL = 'all';

// Friendly names for the logged models, reusing the app's existing labels.
const ENTITY_LABELS: Record<string, TranslationKey> = {
    PumpCounterReading: 'nav.pump_counters',
    FuelPrice: 'nav.fuel_prices',
    FuelType: 'nav.fuel_types',
    FuelPump: 'nav.fuel_pumps',
    Tank: 'nav.tanks',
    TankTopUp: 'inventory.top_up_history',
    TankTransfer: 'inventory.transfer_history',
    InventoryEntry: 'inventory.title',
    Debt: 'nav.debts',
    DebtPayment: 'audit.entity.debt_payment',
    Debtor: 'nav.debtors',
    Transaction: 'nav.transactions',
    ExchangeRate: 'nav.exchange_rates',
    SadcopLedgerEntry: 'nav.sadcop',
    ShopItem: 'nav.shop',
    ShopItemPrice: 'audit.entity.shop_price',
    StationCurrency: 'settings.nav.currencies',
    User: 'nav.employees',
    EarningsPassword: 'earnings.password',
};

const ACTION_LABELS: Record<string, TranslationKey> = {
    created: 'audit.action.created',
    updated: 'audit.action.updated',
    deleted: 'audit.action.deleted',
    'role.assigned': 'audit.action.role_assigned',
    'role.changed': 'audit.action.role_changed',
    'station.reset': 'audit.action.station_reset',
    'backup.restored': 'audit.action.backup_restored',
    'password.admin_override': 'audit.action.password_override',
    'password.admin_reset': 'audit.action.password_admin_reset',
};

const ACTION_TONE: Record<string, string> = {
    created: 'text-green-700 dark:text-green-400',
    updated: 'text-amber-700 dark:text-amber-400',
    deleted: 'text-red-700 dark:text-red-400',
};

// What each logged field is called on screen. Fields not listed get a tidied version of their
// name ("liters_sold" → "Liters sold").
const FIELD_LABELS: Record<string, TranslationKey> = {
    name: 'common.name',
    email: 'common.email',
    amount: 'common.amount',
    currency: 'common.currency',
    liters: 'common.liters',
    quantity_liters: 'common.liters',
    date: 'common.date',
    occurred_at: 'common.date',
    effective_at: 'common.date',
    notes: 'common.notes',
    details: 'common.details',
    description: 'common.details',
    status: 'common.status',
    type: 'common.type',
    direction: 'debts.direction',
    tank_id: 'common.tank',
    from_tank_id: 'common.tank',
    pump_id: 'common.pump',
    fuel_type_id: 'common.fuel_type',
    debtor_id: 'common.debtor',
    price_per_liter: 'transactions.price_per_liter',
    exchange_rate_to_usd: 'transactions.exchange_rate',
    rate_to_usd: 'transactions.exchange_rate',
    capacity_liters: 'inventory.capacity',
    reading_value: 'pump_counters.reading_value',
    is_active: 'tanks.active',
    password: 'audit.field.password',
    must_change_password: 'audit.field.must_change_password',
    phone: 'audit.field.phone',
    slug: 'audit.field.slug',
    user_id: 'audit.field.employee',
    recorded_by_id: 'audit.field.employee',
};

function show(value: unknown, t: (key: TranslationKey) => string): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? t('audit.value.yes') : t('audit.value.no');
    }

    if (value === '[changed]') {
        return t('audit.value.changed');
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/**
 * The changed fields, one per line: "Field  old → new" (arrow flipped in RTL; just the value for
 * creates and deletes). Values are isolated so Latin text and numbers keep their own order
 * inside Arabic text.
 */
function Changes({ log }: { log: AuditLog }) {
    const { t } = useTranslation();
    const { direction } = useLocale();
    const arrow = direction === 'rtl' ? '←' : '→';
    const keys = Array.from(
        new Set([
            ...Object.keys(log.old_values ?? {}),
            ...Object.keys(log.new_values ?? {}),
        ]),
    ).filter((key) => key !== 'id');

    if (keys.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    const label = (key: string) =>
        FIELD_LABELS[key]
            ? t(FIELD_LABELS[key])
            : key
                  .replace(/_id$/, '')
                  .replace(/_/g, ' ')
                  .replace(/^\w/, (c) => c.toUpperCase());

    return (
        <dl className="grid grid-cols-[minmax(6rem,auto)_1fr] gap-x-3 gap-y-1 text-xs">
            {keys.map((key) => (
                <Fragment key={key}>
                    <dt className="text-muted-foreground">{label(key)}</dt>
                    <dd className="break-words">
                        {log.old_values && key in log.old_values && (
                            <>
                                <bdi className="line-through opacity-70">
                                    {show(log.old_values[key], t)}
                                </bdi>{' '}
                                <span aria-hidden="true">{arrow}</span>{' '}
                            </>
                        )}
                        <bdi className="font-medium">
                            {log.new_values && key in log.new_values
                                ? show(log.new_values[key], t)
                                : '—'}
                        </bdi>
                    </dd>
                </Fragment>
            ))}
        </dl>
    );
}

export default function AuditLogIndex() {
    const { logs, filters, entities, actions } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const entityLabel = (entity: string | null) =>
        entity
            ? ENTITY_LABELS[entity]
                ? t(ENTITY_LABELS[entity])
                : entity
            : '—';
    const actionLabel = (action: string) =>
        ACTION_LABELS[action] ? t(ACTION_LABELS[action]) : action;

    function applyFilter(updates: Partial<PageProps['filters']>) {
        router.get(
            index.url(),
            { ...filters, ...updates },
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title={t('audit.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('audit.title')}
                    description={t('audit.description')}
                />

                <SectionToolbar>
                    <Select
                        value={filters.entity ?? ALL}
                        onValueChange={(value) =>
                            applyFilter({
                                entity: value === ALL ? undefined : value,
                            })
                        }
                    >
                        <SelectTrigger className="min-w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('audit.all_entities')}
                            </SelectItem>
                            {entities.map((entity) => (
                                <SelectItem key={entity} value={entity}>
                                    {entityLabel(entity)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.action ?? ALL}
                        onValueChange={(value) =>
                            applyFilter({
                                action: value === ALL ? undefined : value,
                            })
                        }
                    >
                        <SelectTrigger className="min-w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('audit.all_actions')}
                            </SelectItem>
                            {actions.map((action) => (
                                <SelectItem key={action} value={action}>
                                    {actionLabel(action)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </SectionToolbar>

                <div className="table-stack overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">
                                    {t('audit.col_time')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('audit.col_action')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('audit.col_entity')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('audit.col_changes')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('audit.col_user')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((log) => (
                                <tr key={log.id} className="border-t align-top">
                                    {/* The address the change came from sits under its time, leaving the room to the changes. */}
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <div>
                                            {formatDateTime(log.created_at)}
                                        </div>
                                        {log.ip_address && (
                                            <bdi
                                                dir="ltr"
                                                className="text-muted-foreground font-mono text-xs"
                                                title={t('audit.col_ip')}
                                            >
                                                {log.ip_address}
                                            </bdi>
                                        )}
                                    </td>
                                    <td
                                        className={`min-w-[9rem] px-4 py-3 font-medium ${ACTION_TONE[log.action] ?? ''}`}
                                    >
                                        {actionLabel(log.action)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {entityLabel(log.entity_type)}
                                        {log.entity_id !== null && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                #{log.entity_id}
                                            </span>
                                        )}
                                    </td>
                                    <td className="w-1/2 min-w-[18rem] px-4 py-3">
                                        <Changes log={log} />
                                    </td>
                                    <td className="px-4 py-3">
                                        {log.user_name ?? (
                                            <span className="text-muted-foreground">
                                                {t('audit.system')}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {logs.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="text-muted-foreground px-4 py-6 text-center"
                                    >
                                        {t('common.no_results')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <PaginationLinks links={logs.links} />
            </div>
        </>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [{ title: 'nav.audit_log', href: index() }],
};
