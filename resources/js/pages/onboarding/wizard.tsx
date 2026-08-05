import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
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
import { formatNumber } from '@/lib/format';
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
    fuel_type_id: number | null;
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
    return <Badge variant="secondary">Done</Badge>;
}

function SadcopSection({ done }: { done: boolean }) {
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
                    <CardTitle>Sadcop opening balance</CardTitle>
                    {done && <Done />}
                </div>
                <CardDescription>
                    How much money Sadcop currently holds for this station.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="sadcop_amount">Amount (SYP)</Label>
                        <MoneyInput
                            id="sadcop_amount"
                            value={form.data.amount}
                            onChange={(value) => form.setData('amount', value)}
                            disabled={done}
                        />
                    </div>
                    <Button type="submit" disabled={form.processing || done}>
                        Save
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function TankLevelsSection({ tanks }: { tanks: Tank[] }) {
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
                <CardTitle>Starting fuel level per tank</CardTitle>
                <CardDescription>
                    How much fuel is physically in each tank right now.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {tanks.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No tanks yet — add them in Admin &rarr; Tanks first,
                        then come back here.
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
                                    placeholder="Liters"
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
                            Save levels
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
                <CardTitle>Starting counter reading per pump</CardTitle>
                <CardDescription>
                    Each pump's current physical meter reading.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {pumps.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No pumps yet — add them in Admin &rarr; Fuel Pumps
                        first, then come back here.
                    </p>
                )}
                {pumps.length > 0 && (
                    <form onSubmit={submit} className="space-y-3">
                        {pumps.map((pump) => {
                            const availableTanks = pump.fuel_type_id
                                ? tanks.filter(
                                      (tank) =>
                                          tank.fuel_type_id ===
                                          pump.fuel_type_id,
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
                                            <SelectValue placeholder="Tank" />
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
                                        step="0.001"
                                        min="0"
                                        className="max-w-40"
                                        placeholder="Reading"
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
                            Save readings
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function FuelPricesSection({ fuelTypes }: { fuelTypes: FuelTypeRow[] }) {
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
                <CardTitle>Initial fuel prices</CardTitle>
                <CardDescription>
                    Selling price per liter for each fuel type.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {fuelTypes.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No fuel types yet — add them in Admin &rarr; Fuel Types
                        first, then come back here.
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
                            Save prices
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

function DebtsSection({ debts: debtRows }: { debts: DebtRow[] }) {
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
                <CardTitle>Opening debts</CardTitle>
                <CardDescription>
                    Debts owed by existing customers, from before this system.
                    Optional — add as many as you need, or none.
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
                        <Label htmlFor="debtor_name">Debtor name</Label>
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
                        <Label htmlFor="debt_amount">Amount</Label>
                        <MoneyInput
                            id="debt_amount"
                            className="max-w-40"
                            value={form.data.amount}
                            onChange={(value) => form.setData('amount', value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="debt_currency">Currency</Label>
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
                            What for (optional)
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
                        Add debt
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

export default function OnboardingWizard() {
    const {
        sadcopDone,
        tanks,
        pumps,
        fuelTypes,
        debts: debtRows,
    } = usePage<PageProps>().props;

    function finishSetup() {
        router.post(finish.url());
    }

    return (
        <>
            <Head title="Station setup" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Set up your station
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Fill in what applies — everything here is optional
                            and can be corrected later. Finish whenever you're
                            ready.
                        </p>
                    </div>
                    <Button onClick={finishSetup}>Finish setup</Button>
                </div>

                <SadcopSection done={sadcopDone} />
                <TankLevelsSection tanks={tanks} />
                <PumpReadingsSection pumps={pumps} tanks={tanks} />
                <FuelPricesSection fuelTypes={fuelTypes} />
                <DebtsSection debts={debtRows} />

                <div className="flex justify-end">
                    <Button onClick={finishSetup}>Finish setup</Button>
                </div>
            </div>
        </>
    );
}

OnboardingWizard.layout = null;
