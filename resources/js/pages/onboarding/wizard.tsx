import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect } from 'react';
import LocaleTabs from '@/components/locale-tabs';
import { MoneyInput } from '@/components/money-input';
import { Badge } from '@/components/ui/badge';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/hooks/use-locale';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import {
    debts,
    finish,
    fuelPrices,
    pumpReadings,
    sadcopOpeningBalance,
    tankLevels,
} from '@/routes/onboarding';

type Tank = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
    has_opening_level: boolean;
};

type Pump = {
    id: number;
    name: string;
    fuel_type_ids: number[];
    has_reading: boolean;
};

type FuelTypeRow = {
    id: number;
    name: string;
    has_price: boolean;
};

type DebtRow = {
    id: number;
    debtor_name: string;
    amount: string;
    currency: string;
};

type PageProps = {
    sadcopDone: boolean;
    tanks: Tank[];
    pumps: Pump[];
    fuelTypes: FuelTypeRow[];
    debts: DebtRow[];
};

function Done() {
    const { t } = useTranslation();

    return <Badge variant="secondary">{t('wizard.done')}</Badge>;
}

function SadcopSection({ done }: { done: boolean }) {
    const { t } = useTranslation();
    const form = useForm({ amount: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(sadcopOpeningBalance.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <CardTitle>{t('wizard.sadcop_title')}</CardTitle>
                    {done && <Done />}
                </div>
                <CardDescription>
                    {t('wizard.sadcop_description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="sadcop_amount">
                            {t('wizard.sadcop_amount')}
                        </Label>
                        <MoneyInput
                            id="sadcop_amount"
                            value={form.data.amount}
                            onChange={(value) => form.setData('amount', value)}
                            disabled={done}
                        />
                    </div>
                    <Button type="submit" disabled={form.processing || done}>
                        {t('wizard.save')}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function TankLevelsSection({ tanks }: { tanks: Tank[] }) {
    const { t } = useTranslation();
    const form = useForm<{ levels: Record<number, string> }>({ levels: {} });

    function submit(event: FormEvent) {
        event.preventDefault();
        const levels = tanks
            .filter((tank) => form.data.levels[tank.id])
            .map((tank) => ({
                tank_id: tank.id,
                liters: form.data.levels[tank.id],
            }));

        if (levels.length === 0) {
            return;
        }

        form.transform(() => ({ levels }));
        form.post(tankLevels.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('wizard.tank_levels_title')}</CardTitle>
                <CardDescription>
                    {t('wizard.tank_levels_description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {tanks.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('wizard.no_tanks')}
                    </p>
                )}
                {tanks.length > 0 && (
                    <form onSubmit={submit} className="space-y-3">
                        {tanks.map((tank) => (
                            <div
                                key={tank.id}
                                className="flex items-center gap-4"
                            >
                                <div className="w-48 text-sm">
                                    {tank.fuel_type_name} — {tank.name}
                                    {tank.has_opening_level && (
                                        <span className="ms-2">
                                            <Done />
                                        </span>
                                    )}
                                </div>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    className="max-w-40"
                                    placeholder={t('wizard.liters_placeholder')}
                                    value={form.data.levels[tank.id] ?? ''}
                                    onChange={(e) =>
                                        form.setData('levels', {
                                            ...form.data.levels,
                                            [tank.id]: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        <Button type="submit" disabled={form.processing}>
                            {t('wizard.save_levels')}
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function PumpReadingsSection({
    pumps,
    tanks,
}: {
    pumps: Pump[];
    tanks: Tank[];
}) {
    const { t } = useTranslation();
    const form = useForm<{
        readings: Record<number, { reading_value: string; tank_id: string }>;
    }>({ readings: {} });

    function setField(
        pumpId: number,
        field: 'reading_value' | 'tank_id',
        value: string,
    ) {
        form.setData('readings', {
            ...form.data.readings,
            [pumpId]: {
                ...form.data.readings[pumpId],
                [field]: value,
            },
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const readings = pumps
            .filter(
                (pump) =>
                    form.data.readings[pump.id]?.reading_value &&
                    form.data.readings[pump.id]?.tank_id,
            )
            .map((pump) => ({
                pump_id: pump.id,
                tank_id: Number(form.data.readings[pump.id].tank_id),
                reading_value: form.data.readings[pump.id].reading_value,
            }));

        if (readings.length === 0) {
            return;
        }

        form.transform(() => ({ readings }));
        form.post(pumpReadings.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('wizard.pump_readings_title')}</CardTitle>
                <CardDescription>
                    {t('wizard.pump_readings_description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {pumps.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('wizard.no_pumps')}
                    </p>
                )}
                {pumps.length > 0 && (
                    <form onSubmit={submit} className="space-y-3">
                        {pumps.map((pump) => {
                            const availableTanks =
                                pump.fuel_type_ids.length > 0
                                    ? tanks.filter((tank) =>
                                          pump.fuel_type_ids.includes(
                                              tank.fuel_type_id,
                                          ),
                                      )
                                    : tanks;

                            return (
                                <div
                                    key={pump.id}
                                    className="flex flex-wrap items-center gap-4"
                                >
                                    <div className="w-40 text-sm">
                                        {pump.name}
                                        {pump.has_reading && (
                                            <span className="ms-2">
                                                <Done />
                                            </span>
                                        )}
                                    </div>
                                    <Select
                                        value={
                                            form.data.readings[pump.id]
                                                ?.tank_id ?? ''
                                        }
                                        onValueChange={(value) =>
                                            setField(pump.id, 'tank_id', value)
                                        }
                                    >
                                        <SelectTrigger className="w-56">
                                            <SelectValue
                                                placeholder={t(
                                                    'wizard.tank_placeholder',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableTanks.map((tank) => (
                                                <SelectItem
                                                    key={tank.id}
                                                    value={String(tank.id)}
                                                >
                                                    {tank.fuel_type_name} —{' '}
                                                    {tank.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Input
                                        type="number"
                                        step="1"
                                        min="0"
                                        className="max-w-40"
                                        placeholder={t(
                                            'wizard.reading_placeholder',
                                        )}
                                        value={
                                            form.data.readings[pump.id]
                                                ?.reading_value ?? ''
                                        }
                                        onChange={(e) =>
                                            setField(
                                                pump.id,
                                                'reading_value',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            );
                        })}
                        <Button type="submit" disabled={form.processing}>
                            {t('wizard.save_readings')}
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function FuelPricesSection({ fuelTypes }: { fuelTypes: FuelTypeRow[] }) {
    const { t } = useTranslation();
    const form = useForm<{
        prices: Record<number, { price: string; currency: string }>;
    }>({ prices: {} });

    function setField(
        fuelTypeId: number,
        field: 'price' | 'currency',
        value: string,
    ) {
        const existing = form.data.prices[fuelTypeId] ?? {
            price: '',
            currency: 'SYP',
        };

        form.setData('prices', {
            ...form.data.prices,
            [fuelTypeId]: { ...existing, [field]: value },
        });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const prices = fuelTypes
            .filter((ft) => form.data.prices[ft.id]?.price)
            .map((ft) => ({
                fuel_type_id: ft.id,
                price_per_liter: form.data.prices[ft.id].price,
                currency: form.data.prices[ft.id].currency ?? 'SYP',
            }));

        if (prices.length === 0) {
            return;
        }

        form.transform(() => ({ prices }));
        form.post(fuelPrices.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('wizard.fuel_prices_title')}</CardTitle>
                <CardDescription>
                    {t('wizard.fuel_prices_description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {fuelTypes.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('wizard.no_fuel_types')}
                    </p>
                )}
                {fuelTypes.length > 0 && (
                    <form onSubmit={submit} className="space-y-3">
                        {fuelTypes.map((ft) => (
                            <div
                                key={ft.id}
                                className="flex flex-wrap items-center gap-4"
                            >
                                <div className="w-40 text-sm">
                                    {ft.name}
                                    {ft.has_price && (
                                        <span className="ms-2">
                                            <Done />
                                        </span>
                                    )}
                                </div>
                                <MoneyInput
                                    className="max-w-40"
                                    value={form.data.prices[ft.id]?.price ?? ''}
                                    onChange={(value) =>
                                        setField(ft.id, 'price', value)
                                    }
                                />
                                <Select
                                    value={
                                        form.data.prices[ft.id]?.currency ??
                                        'SYP'
                                    }
                                    onValueChange={(value) =>
                                        setField(ft.id, 'currency', value)
                                    }
                                >
                                    <SelectTrigger className="w-28">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="SYP">SYP</SelectItem>
                                        <SelectItem value="TRY">TRY</SelectItem>
                                        <SelectItem value="USD">USD</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        ))}
                        <Button type="submit" disabled={form.processing}>
                            {t('wizard.save_prices')}
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function DebtsSection({ debts: debtRows }: { debts: DebtRow[] }) {
    const { t } = useTranslation();
    const form = useForm({
        debtor_name: '',
        amount: '',
        currency: 'SYP',
        details: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(debts.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('wizard.debts_title')}</CardTitle>
                <CardDescription>
                    {t('wizard.debts_description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {debtRows.length > 0 && (
                    <ul className="space-y-1 text-sm">
                        {debtRows.map((debt) => (
                            <li
                                key={debt.id}
                                className="flex justify-between border-b pb-1"
                            >
                                <span>{debt.debtor_name}</span>
                                <span>
                                    {formatNumber(debt.amount)} {debt.currency}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="debtor_name">
                            {t('wizard.debtor_name')}
                        </Label>
                        <Input
                            id="debtor_name"
                            className="max-w-48"
                            value={form.data.debtor_name}
                            onChange={(e) =>
                                form.setData('debtor_name', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="debt_amount">
                            {t('wizard.debt_amount')}
                        </Label>
                        <MoneyInput
                            id="debt_amount"
                            className="max-w-40"
                            value={form.data.amount}
                            onChange={(value) => form.setData('amount', value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="debt_currency">
                            {t('wizard.debt_currency')}
                        </Label>
                        <Select
                            value={form.data.currency}
                            onValueChange={(value) =>
                                form.setData('currency', value)
                            }
                        >
                            <SelectTrigger id="debt_currency" className="w-28">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="SYP">SYP</SelectItem>
                                <SelectItem value="TRY">TRY</SelectItem>
                                <SelectItem value="USD">USD</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="debt_details">
                            {t('wizard.debt_details')}
                        </Label>
                        <Input
                            id="debt_details"
                            className="max-w-48"
                            value={form.data.details}
                            onChange={(e) =>
                                form.setData('details', e.target.value)
                            }
                        />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        {t('wizard.add_debt')}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

const WIZARD_LOCALE_DEFAULTED_KEY = 'wizard_locale_defaulted';

export default function OnboardingWizard() {
    const { t } = useTranslation();
    const { updateLocale } = useLocale();
    const {
        sadcopDone,
        tanks,
        pumps,
        fuelTypes,
        debts: debtRows,
    } = usePage<PageProps>().props;

    // Station admins here are overwhelmingly Arabic-speaking, and this is the very first
    // screen they see — before they'd have had any chance to visit Settings and pick a
    // language. Default to Arabic once; if they explicitly switch away via the selector
    // below, later reloads of this same wizard won't fight that choice.
    useEffect(() => {
        if (!localStorage.getItem(WIZARD_LOCALE_DEFAULTED_KEY)) {
            localStorage.setItem(WIZARD_LOCALE_DEFAULTED_KEY, '1');
            updateLocale('ar');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function finishSetup() {
        router.post(finish.url());
    }

    return (
        <>
            <Head title={t('wizard.title')} />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {t('wizard.heading')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('wizard.intro')}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <LocaleTabs />
                        <Button onClick={finishSetup}>
                            {t('wizard.finish')}
                        </Button>
                    </div>
                </div>

                <SadcopSection done={sadcopDone} />
                <TankLevelsSection tanks={tanks} />
                <PumpReadingsSection pumps={pumps} tanks={tanks} />
                <FuelPricesSection fuelTypes={fuelTypes} />
                <DebtsSection debts={debtRows} />

                <div className="flex justify-end">
                    <Button onClick={finishSetup}>{t('wizard.finish')}</Button>
                </div>
            </div>
        </>
    );
}

OnboardingWizard.layout = null;
