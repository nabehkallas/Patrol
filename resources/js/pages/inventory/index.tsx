import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import { GenerateXlsxButton } from '@/components/generate-xlsx-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { RowActions } from '@/components/row-actions';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDefaultEntryDate } from '@/hooks/use-default-entry-date';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { selectableTanks } from '@/lib/tanks';
import { cn } from '@/lib/utils';
import { exportTanksXlsx, exportTopupsPdf, index } from '@/routes/inventory';
import {
    destroy as destroyTopUp,
    edit as editTopUp,
    store as storeTopUp,
} from '@/routes/tank-top-ups';
import {
    destroy as destroyTransfer,
    edit as editTransfer,
    store as storeTransfer,
} from '@/routes/tank-transfers';
import type { Auth, TankSummary, TankTopUp, TankTransfer } from '@/types';

type PageProps = {
    auth: Auth;
    tanks: TankSummary[];
    topUps: TankTopUp[];
    transfers: TankTransfer[];
    historyFrom: string;
    historyTo: string;
};

type LogTab = 'topups' | 'transfers';

export default function InventoryIndex() {
    const { auth, tanks, topUps, transfers, historyFrom, historyTo } =
        usePage<PageProps>().props;
    const { t } = useTranslation();
    const defaultEntryDate = useDefaultEntryDate();
    const [logTab, setLogTab] = useState<LogTab>('topups');
    const [topUpLiters, setTopUpLiters] = useState<Record<number, string>>({});
    const [topUpErrors, setTopUpErrors] = useState<Record<number, string>>({});
    const [showTransferForm, setShowTransferForm] = useState(false);

    const transferForm = useForm({
        from_tank_id: String(selectableTanks(tanks)[0]?.id ?? ''),
        to_tank_id: '',
        liters: '',
        date: defaultEntryDate,
        notes: '',
    });

    const transferFromTank = tanks.find(
        (tank) => String(tank.id) === transferForm.data.from_tank_id,
    );
    const transferDestinationOptions = selectableTanks(
        tanks.filter(
            (tank) =>
                tank.fuel_type.id === transferFromTank?.fuel_type.id &&
                String(tank.id) !== transferForm.data.from_tank_id,
        ),
        transferForm.data.to_tank_id,
    );

    function handleTransferFromChange(tankId: string) {
        const nextFromTank = tanks.find((tank) => String(tank.id) === tankId);
        const nextDestinations = selectableTanks(
            tanks.filter(
                (tank) =>
                    tank.fuel_type.id === nextFromTank?.fuel_type.id &&
                    String(tank.id) !== tankId,
            ),
        );

        transferForm.setData((data) => ({
            ...data,
            from_tank_id: tankId,
            to_tank_id: String(nextDestinations[0]?.id ?? ''),
        }));
    }

    function openTransfer(tankId: number) {
        handleTransferFromChange(String(tankId));
        setShowTransferForm(true);
    }

    function submitTransfer(event: FormEvent) {
        event.preventDefault();
        transferForm.post(storeTransfer.url(), {
            preserveScroll: true,
            onSuccess: () => {
                transferForm.reset('liters', 'notes');
                setShowTransferForm(false);
            },
        });
    }

    function submitTopUp(event: FormEvent, tankId: number) {
        event.preventDefault();
        const liters = topUpLiters[tankId] ?? '';

        router.post(
            storeTopUp.url(),
            {
                tank_id: tankId,
                liters,
                date: defaultEntryDate,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTopUpLiters((prev) => ({ ...prev, [tankId]: '' }));
                    setTopUpErrors((prev) => ({ ...prev, [tankId]: '' }));
                },
                onError: (errors) =>
                    setTopUpErrors((prev) => ({
                        ...prev,
                        [tankId]: errors.liters ?? '',
                    })),
            },
        );
    }

    function removeTopUp(topUp: TankTopUp) {
        router.delete(destroyTopUp.url(topUp.id));
    }

    function removeTransfer(transfer: TankTransfer) {
        router.delete(destroyTransfer.url(transfer.id));
    }

    function handleHistoryRangeChange(updates: { from?: string; to?: string }) {
        router.get(
            index(),
            { from: historyFrom, to: historyTo, ...updates },
            { preserveScroll: true, preserveState: true },
        );
    }

    // Sum of added liters per fuel type, over whatever date range topUps was already fetched
    // for (see InventoryEntryController::index()) -- excludes each tank's one-time opening
    // balance top-up (its starting stock when onboarded, not fuel actually supplied during this
    // range), same exclusion EarningsController applies for the same reason. Seeded from every
    // fuel type that actually has a tank (not just ones with a top-up in range), so a fuel type
    // with zero supply this period still shows its own "0 L" card instead of silently
    // disappearing -- that's exactly the "at a glance" answer the card exists to give.
    const topUpTotalsByFuelType = tanks.reduce<
        Record<number, { name: string; liters: number }>
    >((totals, tank) => {
        totals[tank.fuel_type.id] ??= { name: tank.fuel_type.name, liters: 0 };

        return totals;
    }, {});

    topUps
        .filter((topUp) => !topUp.is_opening_balance && topUp.tank?.fuel_type)
        .forEach((topUp) => {
            const fuelType = topUp.tank!.fuel_type!;
            const existing = topUpTotalsByFuelType[fuelType.id] ?? {
                name: fuelType.name,
                liters: 0,
            };

            topUpTotalsByFuelType[fuelType.id] = {
                name: fuelType.name,
                liters: existing.liters + parseFloat(topUp.liters),
            };
        });

    // Colored top border per tank card, keyed by each fuel type's order of first appearance --
    // the first fuel type (typically petrol) gets amber, the second (typically diesel) gets
    // blue, matching the Cash Box page's colored-accent style. Any further fuel type falls back
    // to a neutral border since the station's own fuel type names aren't a fixed enum.
    const TANK_ACCENT_BORDERS = ['border-t-amber-500', 'border-t-blue-500'];
    const fuelTypeAccentBorder: Record<number, string> = {};
    tanks.forEach((tank) => {
        if (!(tank.fuel_type.id in fuelTypeAccentBorder)) {
            fuelTypeAccentBorder[tank.fuel_type.id] =
                TANK_ACCENT_BORDERS[Object.keys(fuelTypeAccentBorder).length] ??
                'border-t-border';
        }
    });

    return (
        <>
            <Head title={t('inventory.title')} />

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title={t('inventory.title')}
                    description={t('inventory.description')}
                />

                <div className="panel-grid">
                    {tanks.map((tank) => {
                        const canTransfer = tanks.some(
                            (other) =>
                                other.id !== tank.id &&
                                other.is_active &&
                                other.fuel_type.id === tank.fuel_type.id,
                        );

                        return (
                            <Card
                                key={tank.id}
                                data-test="tank-card"
                                className={cn(
                                    'gap-4 border-t-4 py-5',
                                    fuelTypeAccentBorder[tank.fuel_type.id],
                                )}
                            >
                                <CardHeader className="px-5">
                                    <CardTitle className="text-lg font-semibold">
                                        {tank.fuel_type.name} — {tank.name}
                                    </CardTitle>
                                    <span className="text-muted-foreground text-xs">
                                        {t('inventory.capacity')}:{' '}
                                        {formatNumber(tank.capacity_liters)} L
                                    </span>
                                </CardHeader>
                                <CardContent className="flex flex-1 flex-col gap-4 px-5 text-sm">
                                    <div>
                                        <p className="text-muted-foreground text-xs">
                                            {t('inventory.amount')}
                                        </p>
                                        <p
                                            className={cn(
                                                'text-3xl font-bold',
                                                tank.expected_liters < 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            <bdi dir="ltr">
                                                {formatNumber(
                                                    tank.expected_liters,
                                                )}{' '}
                                                L
                                            </bdi>
                                        </p>
                                        {tank.expected_liters < 0 && (
                                            <p className="text-destructive bg-destructive/10 mt-2 rounded-md px-2 py-1.5 text-xs font-medium">
                                                {t('inventory.below_zero')}
                                            </p>
                                        )}
                                    </div>

                                    <div className="mt-auto space-y-3 border-t pt-4">
                                        <form
                                            onSubmit={(event) =>
                                                submitTopUp(event, tank.id)
                                            }
                                            className="flex items-center gap-2"
                                        >
                                            <Input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                className="h-9"
                                                placeholder={t(
                                                    'inventory.add_liters_placeholder',
                                                )}
                                                value={
                                                    topUpLiters[tank.id] ?? ''
                                                }
                                                onChange={(e) =>
                                                    setTopUpLiters((prev) => ({
                                                        ...prev,
                                                        [tank.id]:
                                                            e.target.value,
                                                    }))
                                                }
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                className="shrink-0"
                                            >
                                                <Plus />
                                                {t('inventory.add_liters')}
                                            </Button>
                                        </form>
                                        <InputError
                                            message={topUpErrors[tank.id]}
                                        />

                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={!canTransfer}
                                                title={
                                                    canTransfer
                                                        ? undefined
                                                        : t(
                                                              'inventory.no_transfer_target',
                                                          )
                                                }
                                                onClick={() =>
                                                    openTransfer(tank.id)
                                                }
                                            >
                                                <ArrowLeftRight />
                                                {t('inventory.transfer')}
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
                {auth.isAdmin && (
                    <Card
                        className="gap-5 py-5"
                        data-test="inventory-movements"
                    >
                        <CardHeader className="gap-4 px-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <CardTitle className="text-base">
                                    {t('inventory.movements')}
                                </CardTitle>
                                <DateRangePicker
                                    from={historyFrom}
                                    to={historyTo}
                                    onChange={handleHistoryRangeChange}
                                    className="w-full sm:w-auto"
                                />
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="bg-muted inline-flex gap-1 rounded-lg p-1">
                                    {(
                                        [
                                            [
                                                'topups',
                                                t('inventory.top_up_history'),
                                                topUps.length,
                                            ],
                                            [
                                                'transfers',
                                                t('inventory.transfer_history'),
                                                transfers.length,
                                            ],
                                        ] as const
                                    ).map(([value, label, count]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => setLogTab(value)}
                                            className={cn(
                                                'inline-flex items-center gap-2 rounded-md px-3.5 py-1.5 text-sm transition-colors',
                                                logTab === value
                                                    ? 'bg-background shadow-xs font-medium'
                                                    : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                                            )}
                                        >
                                            {label}
                                            <span className="bg-background/80 text-muted-foreground rounded-full px-1.5 text-xs tabular-nums">
                                                {count}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                {logTab === 'topups' && (
                                    <div className="flex flex-wrap gap-2">
                                        <GeneratePdfButton
                                            href={exportTopupsPdf.url({
                                                query: {
                                                    from: historyFrom,
                                                    to: historyTo,
                                                },
                                            })}
                                        />
                                        <GenerateXlsxButton
                                            href={exportTanksXlsx.url({
                                                query: {
                                                    from: historyFrom,
                                                    to: historyTo,
                                                },
                                            })}
                                            label={t(
                                                'inventory.export_tanks_ledger',
                                            )}
                                        />
                                    </div>
                                )}
                            </div>
                        </CardHeader>

                        <CardContent className="space-y-4 px-5">
                            {logTab === 'topups' && (
                                <>
                                    <div className="card-grid">
                                        {Object.values(
                                            topUpTotalsByFuelType,
                                        ).map((total) => (
                                            <div
                                                key={total.name}
                                                className="rounded-lg border border-s-4 border-s-green-500 px-4 py-3"
                                            >
                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'inventory.total_added_liters',
                                                    )}{' '}
                                                    — {total.name}
                                                </p>
                                                <p className="text-xl font-bold">
                                                    {formatNumber(total.liters)}{' '}
                                                    L
                                                </p>
                                            </div>
                                        ))}
                                    </div>

                                    <div className="table-stack overflow-x-auto rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="bg-muted/50 text-start">
                                                    <th className="px-4 py-3">
                                                        {t('common.date')}
                                                    </th>
                                                    <th className="px-4 py-3">
                                                        {t('common.tank')}
                                                    </th>
                                                    <th className="px-4 py-3">
                                                        {t('common.liters')}
                                                    </th>
                                                    <th className="px-4 py-3">
                                                        {t(
                                                            'common.recorded_by',
                                                        )}
                                                    </th>
                                                    <th className="px-4 py-3">
                                                        {t('common.notes')}
                                                    </th>
                                                    <th className="px-4 py-3"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {topUps.map((topUp) => (
                                                    <tr
                                                        key={topUp.id}
                                                        className="border-t"
                                                    >
                                                        <td className="px-4 py-3">
                                                            {formatDate(
                                                                topUp.date,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {
                                                                topUp.tank
                                                                    ?.fuel_type
                                                                    ?.name
                                                            }{' '}
                                                            — {topUp.tank?.name}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {formatNumber(
                                                                topUp.liters,
                                                            )}{' '}
                                                            L
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {
                                                                topUp
                                                                    .recorded_by
                                                                    ?.name
                                                            }
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            {topUp.notes}
                                                        </td>
                                                        <td className="px-4 py-3 text-end">
                                                            <RowActions
                                                                edit={
                                                                    editTopUp(
                                                                        topUp.id,
                                                                    ).url
                                                                }
                                                                remove={() =>
                                                                    removeTopUp(
                                                                        topUp,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                                {topUps.length === 0 && (
                                                    <tr>
                                                        <td
                                                            colSpan={6}
                                                            className="text-muted-foreground px-4 py-6 text-center"
                                                        >
                                                            {t(
                                                                'common.no_results',
                                                            )}
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            )}

                            {logTab === 'transfers' && (
                                <div className="table-stack overflow-x-auto rounded-xl border">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="bg-muted/50 text-start">
                                                <th className="px-4 py-3">
                                                    {t('common.date')}
                                                </th>
                                                <th className="px-4 py-3">
                                                    {t('inventory.from_tank')}
                                                </th>
                                                <th className="px-4 py-3">
                                                    {t('inventory.to_tank')}
                                                </th>
                                                <th className="px-4 py-3">
                                                    {t('common.liters')}
                                                </th>
                                                <th className="px-4 py-3">
                                                    {t('common.recorded_by')}
                                                </th>
                                                <th className="px-4 py-3">
                                                    {t('common.notes')}
                                                </th>
                                                <th className="px-4 py-3"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {transfers.map((transfer) => (
                                                <tr
                                                    key={transfer.id}
                                                    className="border-t"
                                                >
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        {formatDateTime(
                                                            transfer.created_at,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {
                                                            transfer.from_tank
                                                                ?.fuel_type
                                                                ?.name
                                                        }{' '}
                                                        —{' '}
                                                        {
                                                            transfer.from_tank
                                                                ?.name
                                                        }
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {
                                                            transfer.to_tank
                                                                ?.fuel_type
                                                                ?.name
                                                        }{' '}
                                                        —{' '}
                                                        {transfer.to_tank?.name}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {formatNumber(
                                                            transfer.liters,
                                                        )}{' '}
                                                        L
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {
                                                            transfer.recorded_by
                                                                ?.name
                                                        }
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {transfer.notes}
                                                    </td>
                                                    <td className="px-4 py-3 text-end">
                                                        <RowActions
                                                            edit={
                                                                editTransfer(
                                                                    transfer.id,
                                                                ).url
                                                            }
                                                            remove={() =>
                                                                removeTransfer(
                                                                    transfer,
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                            {transfers.length === 0 && (
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
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={showTransferForm} onOpenChange={setShowTransferForm}>
                <DialogContent>
                    <DialogTitle>{t('inventory.transfer_fuel')}</DialogTitle>

                    <form onSubmit={submitTransfer} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="from_tank_id">
                                {t('inventory.from_tank')}
                            </Label>
                            <Select
                                value={transferForm.data.from_tank_id}
                                onValueChange={handleTransferFromChange}
                            >
                                <SelectTrigger
                                    id="from_tank_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {selectableTanks(
                                        tanks,
                                        transferForm.data.from_tank_id,
                                    ).map((tank) => (
                                        <SelectItem
                                            key={tank.id}
                                            value={String(tank.id)}
                                        >
                                            {tank.fuel_type.name} — {tank.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={transferForm.errors.from_tank_id}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="to_tank_id">
                                {t('inventory.to_tank')}
                            </Label>
                            <Select
                                value={transferForm.data.to_tank_id}
                                onValueChange={(value) =>
                                    transferForm.setData('to_tank_id', value)
                                }
                            >
                                <SelectTrigger
                                    id="to_tank_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {transferDestinationOptions.map((tank) => (
                                        <SelectItem
                                            key={tank.id}
                                            value={String(tank.id)}
                                        >
                                            {tank.fuel_type.name} — {tank.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={transferForm.errors.to_tank_id}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="transfer_liters">
                                {t('common.liters')}
                            </Label>
                            <Input
                                id="transfer_liters"
                                type="number"
                                step="0.001"
                                min="0"
                                value={transferForm.data.liters}
                                onChange={(e) =>
                                    transferForm.setData(
                                        'liters',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={transferForm.errors.liters} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="transfer_date">
                                {t('common.date')}
                            </Label>
                            <Input
                                id="transfer_date"
                                type="date"
                                value={transferForm.data.date}
                                onChange={(e) =>
                                    transferForm.setData('date', e.target.value)
                                }
                            />
                            <InputError message={transferForm.errors.date} />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={
                                    transferForm.processing ||
                                    transferDestinationOptions.length === 0
                                }
                            >
                                {t('inventory.transfer')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

InventoryIndex.layout = {
    breadcrumbs: [{ title: 'nav.inventory', href: index() }],
};
