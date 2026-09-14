import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { formatNumber, formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import earnings from '@/routes/admin/earnings';
import type {
    EarningsBreakdownRow,
    EarningsRevaluation,
    ShopProfitSummary,
} from '@/types';

/**
 * Isolates a money figure from the surrounding RTL paragraph direction (via <bdi>) and lets
 * formatSyp embed the sign directly in the number, instead of a separate leading "-" text node
 * next to it -- the combination is what the Arabic UI needs to keep a negative amount's minus
 * sign on the correct (leading) side instead of drifting to the visual end of the string.
 */
function Syp({ value }: { value: number }) {
    return <bdi>{formatSyp(value)}</bdi>;
}

/** Same RTL-safe wrapping as <Syp>, but at 3 decimal places -- for a per-liter margin RATE,
 * where the exact multiplication factor behind margin_earnings_syp needs to stay visible
 * (e.g. 4.542 SYP, not a rounded-looking 4.5 SYP). */
function SypRate({ value }: { value: number }) {
    return <bdi>{formatNumber(value, 3)} SYP</bdi>;
}

type LockedProps = {
    locked: true;
    needsSetup: boolean;
};

type UnlockedProps = {
    locked: false;
    filters: { from: string; to: string };
    breakdown: EarningsBreakdownRow[];
    revaluation: EarningsRevaluation;
    shop_profit: ShopProfitSummary;
    other_expense_syp: number;
    total_earnings_syp: number;
};

type PageProps = LockedProps | UnlockedProps;

const EARNINGS_PASSWORD_RESET_WHATSAPP_URL =
    'https://wa.me/963997361673?text=' +
    encodeURIComponent('I forgot the Earnings password.');

function EarningsGate({ needsSetup }: { needsSetup: boolean }) {
    const { t } = useTranslation();

    const form = useForm({
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (needsSetup) {
            form.post(earnings.setup.url(), {
                onFinish: () => form.reset('password', 'password_confirmation'),
            });
        } else {
            form.post(earnings.unlock.url(), {
                onFinish: () => form.reset('password'),
            });
        }
    }

    return (
        <div className="max-w-sm space-y-6">
            <Heading
                variant="small"
                title={t('earnings.title')}
                description={
                    needsSetup
                        ? t('earnings.setup_description')
                        : t('earnings.locked_description')
                }
            />

            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-2">
                    <Label htmlFor="password">{t('earnings.password')}</Label>
                    <PasswordInput
                        id="password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        autoFocus
                    />
                    <InputError message={form.errors.password} />
                </div>

                {needsSetup && (
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            {t('common.password_confirmation')}
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError
                            message={form.errors.password_confirmation}
                        />
                    </div>
                )}

                <Button type="submit" disabled={form.processing}>
                    {needsSetup
                        ? t('earnings.set_password')
                        : t('earnings.unlock')}
                </Button>
            </form>

            {!needsSetup && (
                <a
                    href={EARNINGS_PASSWORD_RESET_WHATSAPP_URL}
                    className="text-muted-foreground text-sm underline"
                >
                    {t('earnings.forgot_password')}
                </a>
            )}
        </div>
    );
}

function DetailCard({
    row,
    t,
}: {
    row: EarningsBreakdownRow;
    t: (key: TranslationKey) => string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{row.fuel_type.name}</CardTitle>
                <CardDescription className="text-foreground text-lg font-semibold">
                    <Syp value={row.subtotal_syp} />
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.liters_sold')}
                    </span>
                    <span>{formatNumber(row.liters_sold)} L</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.profit_margin_percent')}
                    </span>
                    <span>{formatNumber(row.profit_margin_percent, 4)}%</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.profit_margin')}
                    </span>
                    <span>
                        <SypRate value={row.profit_margin_syp} />
                    </span>
                </div>
                {row.margin_tiers.map((tier, index) => (
                    <div
                        key={index}
                        className="text-muted-foreground flex justify-between text-xs"
                    >
                        <span>
                            {formatNumber(tier.liters)} L ×{' '}
                            <SypRate value={tier.margin_rate_syp} />
                        </span>
                        <span>
                            <Syp value={tier.earnings_syp} />
                        </span>
                    </div>
                ))}
                <div className="flex justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('earnings.margin_earnings')}
                    </span>
                    <span>
                        <Syp value={row.margin_earnings_syp} />
                    </span>
                </div>
                <div className="my-2 border-t" />
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.topup_liters')}
                    </span>
                    <span>{formatNumber(row.topup_liters)} L</span>
                </div>
                {row.topup_tiers.map((tier, index) => (
                    <div
                        key={index}
                        className="text-muted-foreground flex justify-between text-xs"
                    >
                        <span>
                            {formatNumber(tier.liters)} L ×{' '}
                            <Syp value={tier.price_per_liter_syp} />
                        </span>
                        <span>
                            <Syp value={tier.earnings_syp} />
                        </span>
                    </div>
                ))}
                <div className="flex justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('earnings.topup_earnings')}
                    </span>
                    <span>
                        <Syp value={row.topup_earnings_syp} />
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function ShopProfitCard({
    shopProfit,
    t,
}: {
    shopProfit: ShopProfitSummary;
    t: (key: TranslationKey) => string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('earnings.shop_profit_title')}</CardTitle>
                <CardDescription className="text-foreground text-lg font-semibold">
                    <Syp value={shopProfit.net_profit_syp} />
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.shop_revenue')}
                    </span>
                    <span>
                        <Syp value={shopProfit.total_revenue_syp} />
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.shop_cogs')}
                    </span>
                    <span>
                        <Syp value={shopProfit.total_cogs_syp} />
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.shop_margin_percent')}
                    </span>
                    <span>
                        {formatNumber(shopProfit.average_margin_percent, 2)}%
                    </span>
                </div>
                <div className="flex justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('earnings.shop_net_profit')}
                    </span>
                    <span>
                        <Syp value={shopProfit.net_profit_syp} />
                    </span>
                </div>
                {shopProfit.items.length > 0 && (
                    <>
                        <div className="my-2 border-t" />
                        <div className="text-muted-foreground text-xs font-medium">
                            {t('earnings.shop_item')}
                        </div>
                        <div className="space-y-2">
                            {shopProfit.items.map((item) => (
                                <div key={item.id} className="space-y-0.5">
                                    <div className="text-xs font-medium">
                                        {item.name}
                                    </div>
                                    <div className="text-muted-foreground flex justify-between text-xs">
                                        <span>
                                            {item.quantity_sold} ×{' '}
                                            <Syp
                                                value={item.profit_per_unit_syp}
                                            />
                                        </span>
                                        <span>
                                            <Syp
                                                value={item.total_profit_syp}
                                            />
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * A one-time gain from selling inventory that was bought/valued before the fuel type's price
 * was last raised -- not repeatable operational margin, so it's deliberately kept out of the
 * Petrol/Diesel cards above and shown here on its own instead.
 */
function RevaluationCard({
    revaluation,
    t,
}: {
    revaluation: EarningsRevaluation;
    t: (key: TranslationKey) => string;
}) {
    if (revaluation.items.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('earnings.revaluation_title')}</CardTitle>
                <CardDescription className="text-foreground text-lg font-semibold">
                    <Syp value={revaluation.total_syp} />
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
                {revaluation.items.map((item) => (
                    <div
                        key={item.fuel_type.id}
                        className="flex justify-between"
                    >
                        <span className="text-muted-foreground">
                            {item.fuel_type.name} ({formatNumber(item.liters)}{' '}
                            L)
                        </span>
                        <span>
                            <Syp value={item.profit_syp} />
                        </span>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function EarningsReport({
    filters,
    breakdown,
    revaluation,
    shop_profit,
    other_expense_syp,
    total_earnings_syp,
}: UnlockedProps) {
    const { t } = useTranslation();

    const [fromVal, setFromVal] = useState(filters.from);
    const [toVal, setToVal] = useState(filters.to);

    function apply() {
        router.get(
            earnings.index.url(),
            { from: fromVal, to: toVal },
            { preserveState: false },
        );
    }

    return (
        <>
            <Head title={t('earnings.title')} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('earnings.title')}
                    description={t('earnings.description')}
                />

                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-4">
                            <DateRangePicker
                                from={fromVal}
                                to={toVal}
                                onChange={(range) => {
                                    setFromVal(range.from);
                                    setToVal(range.to);
                                }}
                            />
                            <Button onClick={apply}>
                                {t('statistics.apply')}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap gap-4">
                    <Card className="min-w-[12rem] max-w-xs flex-1">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                {t('earnings.total_earnings')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            <Syp value={total_earnings_syp} />
                        </CardContent>
                    </Card>
                    <Card className="min-w-[12rem] max-w-xs flex-1">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                {t('cash_box.other_expenses')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            <Syp value={-other_expense_syp} />
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {breakdown.map((row) => (
                        <DetailCard key={row.fuel_type.id} row={row} t={t} />
                    ))}
                    <ShopProfitCard shopProfit={shop_profit} t={t} />
                    <RevaluationCard revaluation={revaluation} t={t} />
                </div>
            </div>
        </>
    );
}

export default function EarningsIndex() {
    const props = usePage<PageProps>().props;
    const { t } = useTranslation();

    if (props.locked) {
        return (
            <>
                <Head title={t('earnings.title')} />
                <EarningsGate needsSetup={props.needsSetup} />
            </>
        );
    }

    return <EarningsReport {...props} />;
}

EarningsIndex.layout = {
    breadcrumbs: [{ title: 'Earnings', href: earnings.index() }],
};
