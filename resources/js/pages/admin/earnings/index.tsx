import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber, formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import earnings from '@/routes/admin/earnings';
import type { EarningsBreakdownRow } from '@/types';

type LockedProps = {
    locked: true;
    needsSetup: boolean;
};

type UnlockedProps = {
    locked: false;
    filters: { from: string; to: string };
    breakdown: EarningsBreakdownRow[];
    other_expense_syp: number;
    total_earnings_syp: number;
};

type PageProps = LockedProps | UnlockedProps;

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
                <Link
                    href={earnings.forgotPassword.url()}
                    className="text-sm text-muted-foreground underline"
                >
                    {t('earnings.forgot_password')}
                </Link>
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
                <CardDescription className="text-lg font-semibold text-foreground">
                    {formatSyp(row.subtotal_syp)}
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
                    <span>{formatNumber(row.profit_margin_percent)}%</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.profit_margin')}
                    </span>
                    <span>{formatSyp(row.profit_margin_syp)}</span>
                </div>
                <div className="flex justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('earnings.margin_earnings')}
                    </span>
                    <span>{formatSyp(row.margin_earnings_syp)}</span>
                </div>
                <div className="my-2 border-t" />
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.topup_liters')}
                    </span>
                    <span>{formatNumber(row.topup_liters)} L</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-muted-foreground">
                        {t('earnings.sale_price')}
                    </span>
                    <span>{formatSyp(row.price_per_liter_syp)}</span>
                </div>
                <div className="flex justify-between font-medium">
                    <span className="text-muted-foreground">
                        {t('earnings.topup_earnings')}
                    </span>
                    <span>{formatSyp(row.topup_earnings_syp)}</span>
                </div>
            </CardContent>
        </Card>
    );
}

function EarningsReport({
    filters,
    breakdown,
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
                            <div className="grid gap-1">
                                <Label htmlFor="from">
                                    {t('statistics.from')}
                                </Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={fromVal}
                                    onChange={(e) => setFromVal(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="to">{t('statistics.to')}</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={toVal}
                                    onChange={(e) => setToVal(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <Button onClick={apply}>
                                {t('statistics.apply')}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap gap-4">
                    <Card className="max-w-xs min-w-[12rem] flex-1">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                {t('earnings.total_earnings')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {formatSyp(total_earnings_syp)}
                        </CardContent>
                    </Card>
                    <Card className="max-w-xs min-w-[12rem] flex-1">
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                {t('cash_box.other_expenses')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            -{formatSyp(other_expense_syp)}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {breakdown.map((row) => (
                        <DetailCard key={row.fuel_type.id} row={row} t={t} />
                    ))}
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
