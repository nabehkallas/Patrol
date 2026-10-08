import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
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
};

const ACTION_TONE: Record<string, string> = {
    created: 'text-green-700 dark:text-green-400',
    updated: 'text-amber-700 dark:text-amber-400',
    deleted: 'text-red-700 dark:text-red-400',
};

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/** The changed fields as "field: old → new" (arrow flipped in RTL; values only for create/delete). */
function Changes({ log }: { log: AuditLog }) {
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

    return (
        <ul className="space-y-0.5 text-xs">
            {keys.map((key) => (
                <li key={key} className="break-all">
                    <span className="text-muted-foreground font-mono">
                        {key}
                    </span>
                    :{' '}
                    {log.old_values && key in log.old_values && (
                        <>
                            <bdi className="line-through opacity-70">
                                {show(log.old_values[key])}
                            </bdi>{' '}
                            {arrow}{' '}
                        </>
                    )}
                    <bdi className="font-medium">
                        {log.new_values && key in log.new_values
                            ? show(log.new_values[key])
                            : '—'}
                    </bdi>
                </li>
            ))}
        </ul>
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
                <Heading
                    variant="small"
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
                        <SelectTrigger className="w-56">
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
                        <SelectTrigger className="w-48">
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
                                    {t('audit.col_user')}
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
                                    {t('audit.col_ip')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((log) => (
                                <tr key={log.id} className="border-t align-top">
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {formatDateTime(log.created_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {log.user_name ?? '—'}
                                    </td>
                                    <td
                                        className={`whitespace-nowrap px-4 py-3 font-medium ${ACTION_TONE[log.action] ?? ''}`}
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
                                    <td className="max-w-md px-4 py-3">
                                        <Changes log={log} />
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs">
                                        <bdi dir="ltr">
                                            {log.ip_address ?? '—'}
                                        </bdi>
                                    </td>
                                </tr>
                            ))}
                            {logs.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
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
    breadcrumbs: [{ title: 'Audit log', href: index() }],
};
