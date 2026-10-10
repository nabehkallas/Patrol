import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    AlertTriangle,
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    CheckCircle2,
    Cylinder,
    Fuel,
    Gauge,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/page-header';
import { TankLevel } from '@/components/tank-level';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    formatCurrencyAmount,
    formatDate,
    formatNonZeroBreakdown,
    formatNumber,
} from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { today } from '@/routes';
import { index as exchangeRates } from '@/routes/admin/exchange-rates';
import { index as fuelPrices } from '@/routes/admin/fuel-prices';
import { index as cashBox } from '@/routes/cash-box';
import { index as debts } from '@/routes/debts';
import { index as inventory } from '@/routes/inventory';
import { index as pumpCounters } from '@/routes/pump-counters';
import type { Auth, CurrencyBreakdown } from '@/types';

type TankState = 'ok' | 'low' | 'negative';

type PageProps = {
    auth: Auth;
    day: string;
    pumps: { id: number; name: string; recorded: boolean }[];
    money: { in: CurrencyBreakdown; out: CurrencyBreakdown; count: number };
    tanks: {
        id: number;
        name: string;
        fuel_type: string;
        liters: number;
        capacity: number;
        state: TankState;
    }[];
    debts: { receivable: CurrencyBreakdown; payable: CurrencyBreakdown };
    prices: {
        fuel_type: string;
        price: { price_per_liter: string; currency: string } | null;
    }[];
    rates: { currency: string; rate: number | null }[];
};

/** One section of the overview: a titled card with a link to the screen it summarises. */
function Panel({
    title,
    icon: Icon,
    href,
    linkLabel,
    children,
}: {
    title: string;
    icon: LucideIcon;
    href?: string;
    linkLabel?: string;
    children: ReactNode;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon className="text-muted-foreground size-4" />
                    {title}
                </CardTitle>
                {href && linkLabel && (
                    <Link
                        href={href}
                        className="text-primary text-sm font-medium hover:underline"
                    >
                        {linkLabel}
                    </Link>
                )}
            </CardHeader>
            <CardContent className="space-y-3">{children}</CardContent>
        </Card>
    );
}

export default function Today() {
    const {
        auth,
        day,
        pumps,
        money,
        tanks,
        debts: owed,
        prices,
        rates,
    } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const missingPumps = pumps.filter((pump) => !pump.recorded);
    const negativeTanks = tanks.filter((tank) => tank.state === 'negative');
    const lowTanks = tanks.filter((tank) => tank.state === 'low');
    const missingRates = rates.filter((rate) => rate.rate === null);

    // What needs doing, most urgent first. Each item links to where it's fixed.
    const attention: { key: string; text: string; href: string }[] = [
        ...negativeTanks.map((tank) => ({
            key: `neg-${tank.id}`,
            text: t('today.alert.negative', { tank: tank.name }),
            href: inventory.url(),
        })),
        ...(missingPumps.length > 0
            ? [
                  {
                      key: 'pumps',
                      text: t('today.alert.pumps', {
                          count: missingPumps.length,
                          names: missingPumps.map((p) => p.name).join(', '),
                      }),
                      href: pumpCounters.url(),
                  },
              ]
            : []),
        ...lowTanks.map((tank) => ({
            key: `low-${tank.id}`,
            text: t('today.alert.low', { tank: tank.name }),
            href: inventory.url(),
        })),
        ...(auth.isAdmin
            ? missingRates.map((rate) => ({
                  key: `rate-${rate.currency}`,
                  text: t('today.alert.rate', { currency: rate.currency }),
                  href: exchangeRates.url(),
              }))
            : []),
    ];

    const recordedCount = pumps.length - missingPumps.length;

    return (
        <>
            <Head title={t('nav.today')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('nav.today')}
                    description={formatDate(day)}
                />

                {attention.length > 0 ? (
                    <div className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/40">
                        <p className="flex items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-200">
                            <AlertTriangle className="size-4" />
                            {t('today.needs_attention')}
                        </p>
                        <ul className="space-y-1.5 text-sm">
                            {attention.map((item) => (
                                <li key={item.key}>
                                    <Link
                                        href={item.href}
                                        className="text-amber-900 underline-offset-2 hover:underline dark:text-amber-100"
                                    >
                                        {item.text}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <div className="flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm font-medium text-emerald-900 dark:border-emerald-700/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                        <CheckCircle2 className="size-4" />
                        {t('today.all_set')}
                    </div>
                )}

                <div className="panel-grid">
                    <Panel
                        title={t('nav.pump_counters')}
                        icon={Gauge}
                        href={pumpCounters.url()}
                        linkLabel={t('today.enter_readings')}
                    >
                        {pumps.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('today.no_pumps')}
                            </p>
                        ) : (
                            <>
                                <p className="text-2xl font-bold">
                                    {t('today.pumps_recorded', {
                                        done: recordedCount,
                                        total: pumps.length,
                                    })}
                                </p>
                                <div className="bg-muted h-2 overflow-hidden rounded-full">
                                    <div
                                        className="h-full rounded-full bg-emerald-500"
                                        style={{
                                            width: `${(recordedCount / pumps.length) * 100}%`,
                                        }}
                                    />
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {pumps.map((pump) => (
                                        <span
                                            key={pump.id}
                                            title={pump.name}
                                            className={cn(
                                                'max-w-[min(100%,10rem)] truncate rounded-full border px-2.5 py-0.5 text-xs [unicode-bidi:plaintext]',
                                                pump.recorded
                                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {pump.name}
                                        </span>
                                    ))}
                                </div>
                            </>
                        )}
                    </Panel>

                    <Panel
                        title={t('today.money')}
                        icon={Banknote}
                        href={cashBox.url()}
                        linkLabel={t('today.open_cash_box')}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                <ArrowDownLeft className="size-4 text-emerald-600" />
                                {t('today.money_in')}
                            </span>
                            <span className="font-semibold">
                                {formatNonZeroBreakdown(money.in)}
                            </span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                <ArrowUpRight className="size-4 text-rose-600" />
                                {t('today.money_out')}
                            </span>
                            <span className="font-semibold">
                                {formatNonZeroBreakdown(money.out)}
                            </span>
                        </div>
                        <p className="text-muted-foreground border-t pt-2 text-xs">
                            {t('today.entries', { count: money.count })}
                        </p>
                    </Panel>

                    <Panel
                        title={t('nav.inventory')}
                        icon={Cylinder}
                        href={inventory.url()}
                        linkLabel={t('today.open_inventory')}
                    >
                        {tanks.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('common.no_results')}
                            </p>
                        ) : (
                            tanks.map((tank) => (
                                <div key={tank.id} className="space-y-1">
                                    <div className="text-sm font-medium">
                                        {tank.fuel_type} — {tank.name}
                                    </div>
                                    <TankLevel
                                        liters={tank.liters}
                                        capacity={tank.capacity}
                                    />
                                </div>
                            ))
                        )}
                    </Panel>

                    <Panel
                        title={t('nav.debts')}
                        icon={Banknote}
                        href={debts.url()}
                        linkLabel={t('today.open_debts')}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-sm text-emerald-700 dark:text-emerald-400">
                                {t('today.owed_to_us')}
                            </span>
                            <span className="font-semibold">
                                {formatNonZeroBreakdown(owed.receivable)}
                            </span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-sm text-rose-700 dark:text-rose-400">
                                {t('today.we_owe')}
                            </span>
                            <span className="font-semibold">
                                {formatNonZeroBreakdown(owed.payable)}
                            </span>
                        </div>
                    </Panel>

                    <Panel
                        title={t('today.prices')}
                        icon={Fuel}
                        href={auth.isAdmin ? fuelPrices.url() : undefined}
                        linkLabel={t('today.change')}
                    >
                        {prices.map((row) => (
                            <div
                                key={row.fuel_type}
                                className="flex items-center justify-between gap-3 text-sm"
                            >
                                <span>{row.fuel_type}</span>
                                <span className="font-semibold">
                                    {row.price
                                        ? formatCurrencyAmount(
                                              row.price.price_per_liter,
                                              row.price.currency,
                                          )
                                        : t('today.no_price')}
                                </span>
                            </div>
                        ))}
                        {rates.length > 0 && (
                            <div className="space-y-1 border-t pt-2">
                                {rates.map((rate) => (
                                    <div
                                        key={rate.currency}
                                        className="text-muted-foreground flex items-center justify-between gap-3 text-xs"
                                    >
                                        <bdi dir="ltr">1 USD</bdi>
                                        <span>
                                            {rate.rate === null
                                                ? t('today.no_rate', {
                                                      currency: rate.currency,
                                                  })
                                                : `${formatNumber(rate.rate, 6)} ${rate.currency}`}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

Today.layout = {
    breadcrumbs: [{ title: 'nav.today', href: today() }],
};
