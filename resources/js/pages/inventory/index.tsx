import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DateRangePicker } from '@/components/date-range-picker';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PaginationLinks from '@/components/pagination-links';
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
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    exportEntriesPdf,
    exportTopupsPdf,
    index,
    store,
} from '@/routes/inventory';
import { destroy, edit } from '@/routes/inventory/entries';
import { store as storeTopUp } from '@/routes/tank-top-ups';
import { store as storeTransfer } from '@/routes/tank-transfers';
import type {
    Auth,
    InventoryEntry,
    Paginated,
    TankSummary,
    TankTopUp,
    TankTransfer,
} from '@/types';

type PageProps = {
    auth: Auth;
    tanks: TankSummary[];
    entries: Paginated<InventoryEntry>;
    topUps: TankTopUp[];
    transfers: TankTransfer[];
    historyFrom: string;
    historyTo: string;
};

type Tab = 'amounts' | 'actual';

export default function InventoryIndex() {
    const { auth, tanks, entries, topUps, transfers, historyFrom, historyTo } =
        usePage<PageProps>().props;
    const { t } = useTranslation();
    const [activeTab, setActiveTab] = useState<Tab>('amounts');
    const [topUpLiters, setTopUpLiters] = useState<Record<number, string>>({});
    const [topUpErrors, setTopUpErrors] = useState<Record<number, string>>({});
    const [showTransferForm, setShowTransferForm] = useState(false);

    const form = useForm({
        tank_id: String(tanks[0]?.id ?? ''),
        date: new Date().toISOString().slice(0, 10),
        quantity_liters: '',
        notes: '',
    });

    const transferForm = useForm({
        from_tank_id: String(tanks[0]?.id ?? ''),
        to_tank_id: '',
        liters: '',
        date: new Date().toISOString().slice(0, 10),
        notes: '',
    });

    const transferFromTank = tanks.find(
        (tank) => String(tank.id) === transferForm.data.from_tank_id,
    );
    const transferDestinationOptions = tanks.filter(
        (tank) =>
            tank.fuel_type.id === transferFromTank?.fuel_type.id &&
            String(tank.id) !== transferForm.data.from_tank_id,
    );

    function handleTransferFromChange(tankId: string) {
        const nextFromTank = tanks.find((tank) => String(tank.id) === tankId);
        const nextDestinations = tanks.filter(
            (tank) =>
                tank.fuel_type.id === nextFromTank?.fuel_type.id &&
                String(tank.id) !== tankId,
        );

        transferForm.setData((data) => ({
            ...data,
            from_tank_id: tankId,
            to_tank_id: String(nextDestinations[0]?.id ?? ''),
        }));
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

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            onSuccess: () => form.reset('quantity_liters', 'notes'),
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
                date: new Date().toISOString().slice(0, 10),
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

    function removeEntry(entry: InventoryEntry) {
        if (confirm(t('common.confirm_delete'))) {
            router.delete(destroy.url(entry.id));
        }
    }

    function handleHistoryRangeChange(updates: { from?: string; to?: string }) {
        router.get(
            index(),
            { from: historyFrom, to: historyTo, ...updates },
            { preserveScroll: true, preserveState: true },
        );
    }

    const tabs: { value: Tab; label: string }[] = [
        { value: 'amounts', label: t('inventory.tab_amounts') },
        { value: 'actual', label: t('inventory.tab_actual') },
    ];

    return (
        <>
            <Head title={t('inventory.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('inventory.title')}
                        description={t('inventory.description')}
                    />
                    <Button
                        type="button"
                        onClick={() => setShowTransferForm(true)}
                    >
                        {t('inventory.transfer_fuel')}
                    </Button>
                </div>

                <div className="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                    {tabs.map(({ value, label }) => (
                        <button
                            key={value}
                            onClick={() => setActiveTab(value)}
                            className={cn(
                                'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                                activeTab === value
                                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {activeTab === 'amounts' && (
                    <div className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-3">
                            {tanks.map((tank) => (
                                <Card key={tank.id}>
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between text-base">
                                            <span>
                                                {tank.fuel_type.name} —{' '}
                                                {tank.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t('inventory.capacity')}:{' '}
                                                {formatNumber(
                                                    tank.capacity_liters,
                                                )}{' '}
                                                L
                                            </span>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">
                                                {t('inventory.amount')}
                                            </span>
                                            <span>
                                                {formatNumber(
                                                    tank.expected_liters,
                                                )}{' '}
                                                L
                                            </span>
                                        </div>

                                        <form
                                            onSubmit={(event) =>
                                                submitTopUp(event, tank.id)
                                            }
                                            className="flex items-start gap-2 border-t pt-3"
                                        >
                                            <div className="grid flex-1 gap-1">
                                                <Input
                                                    type="number"
                                                    step="0.001"
                                                    min="0"
                                                    placeholder={t(
                                                        'inventory.add_liters_placeholder',
                                                    )}
                                                    value={
                                                        topUpLiters[tank.id] ??
                                                        ''
                                                    }
                                                    onChange={(e) =>
                                                        setTopUpLiters(
                                                            (prev) => ({
                                                                ...prev,
                                                                [tank.id]:
                                                                    e.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        topUpErrors[tank.id]
                                                    }
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                            >
                                                {t('inventory.add_liters')}
                                            </Button>
                                        </form>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        <DateRangePicker
                            from={historyFrom}
                            to={historyTo}
                            onChange={handleHistoryRangeChange}
                        />

                        <div className="space-y-3">
                            <div className="flex items-center gap-4">
                                <h3 className="font-semibold">
                                    {t('inventory.top_up_history')}
                                </h3>
                                <GeneratePdfButton
                                    href={exportTopupsPdf.url({
                                        query: {
                                            from: historyFrom,
                                            to: historyTo,
                                        },
                                    })}
                                />
                            </div>

                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-4 py-2">
                                                {t('common.tank')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.liters')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.recorded_by')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.notes')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {topUps.map((topUp) => (
                                            <tr
                                                key={topUp.id}
                                                className="border-t"
                                            >
                                                <td className="px-4 py-2">
                                                    {
                                                        topUp.tank?.fuel_type
                                                            ?.name
                                                    }{' '}
                                                    — {topUp.tank?.name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatNumber(topUp.liters)}{' '}
                                                    L
                                                </td>
                                                <td className="px-4 py-2">
                                                    {topUp.recorded_by?.name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {topUp.notes}
                                                </td>
                                            </tr>
                                        ))}
                                        {topUps.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={4}
                                                    className="px-4 py-6 text-center text-muted-foreground"
                                                >
                                                    {t('common.no_results')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="space-y-3">
                            <h3 className="font-semibold">
                                {t('inventory.transfer_history')}
                            </h3>
                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-start">
                                            <th className="px-4 py-2">
                                                {t('inventory.from_tank')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('inventory.to_tank')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.liters')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.recorded_by')}
                                            </th>
                                            <th className="px-4 py-2">
                                                {t('common.notes')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {transfers.map((transfer) => (
                                            <tr
                                                key={transfer.id}
                                                className="border-t"
                                            >
                                                <td className="px-4 py-2">
                                                    {
                                                        transfer.from_tank
                                                            ?.fuel_type?.name
                                                    }{' '}
                                                    — {transfer.from_tank?.name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {
                                                        transfer.to_tank
                                                            ?.fuel_type?.name
                                                    }{' '}
                                                    — {transfer.to_tank?.name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {formatNumber(
                                                        transfer.liters,
                                                    )}{' '}
                                                    L
                                                </td>
                                                <td className="px-4 py-2">
                                                    {transfer.recorded_by?.name}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {transfer.notes}
                                                </td>
                                            </tr>
                                        ))}
                                        {transfers.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="px-4 py-6 text-center text-muted-foreground"
                                                >
                                                    {t('common.no_results')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {activeTab === 'actual' && (
                    <div className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-3">
                            {tanks.map((tank) => {
                                const hasVariance =
                                    tank.variance_liters !== null &&
                                    Math.abs(tank.variance_liters) > 0.01;

                                return (
                                    <Card key={tank.id}>
                                        <CardHeader>
                                            <CardTitle className="flex items-center justify-between text-base">
                                                <span>
                                                    {tank.fuel_type.name} —{' '}
                                                    {tank.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {t('inventory.capacity')}:{' '}
                                                    {formatNumber(
                                                        tank.capacity_liters,
                                                    )}{' '}
                                                    L
                                                </span>
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-1 text-sm">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    {t('dashboard.actual')}
                                                </span>
                                                <span>
                                                    {tank.latest_reading
                                                        ? `${formatNumber(tank.latest_reading.quantity_liters)} L`
                                                        : t(
                                                              'inventory.no_reading',
                                                          )}
                                                </span>
                                            </div>
                                            {tank.variance_liters !== null && (
                                                <div className="flex justify-between font-medium">
                                                    <span className="text-muted-foreground">
                                                        {t(
                                                            'dashboard.variance',
                                                        )}
                                                    </span>
                                                    <span
                                                        className={cn(
                                                            hasVariance &&
                                                                'text-destructive',
                                                        )}
                                                    >
                                                        {tank.variance_liters >
                                                        0
                                                            ? '+'
                                                            : ''}
                                                        {formatNumber(
                                                            tank.variance_liters,
                                                        )}{' '}
                                                        L
                                                    </span>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('inventory.record_today')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={submit}
                                    className="grid gap-4 md:grid-cols-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="tank_id">
                                            {t('common.tank')}
                                        </Label>
                                        <Select
                                            value={form.data.tank_id}
                                            onValueChange={(value) =>
                                                form.setData('tank_id', value)
                                            }
                                        >
                                            <SelectTrigger
                                                id="tank_id"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {tanks.map((tank) => (
                                                    <SelectItem
                                                        key={tank.id}
                                                        value={String(tank.id)}
                                                    >
                                                        {tank.fuel_type.name} —{' '}
                                                        {tank.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={form.errors.tank_id}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="date">
                                            {t('common.date')}
                                        </Label>
                                        <Input
                                            id="date"
                                            type="date"
                                            value={form.data.date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.date}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="quantity_liters">
                                            {t('inventory.quantity')}
                                        </Label>
                                        <Input
                                            id="quantity_liters"
                                            type="number"
                                            step="0.001"
                                            min="0"
                                            value={form.data.quantity_liters}
                                            onChange={(e) =>
                                                form.setData(
                                                    'quantity_liters',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                form.errors.quantity_liters
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="notes">
                                            {t('common.notes')}
                                        </Label>
                                        <Textarea
                                            id="notes"
                                            value={form.data.notes}
                                            onChange={(e) =>
                                                form.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                        className="md:col-span-4 md:w-fit"
                                    >
                                        {t('inventory.save_entry')}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <div className="flex justify-end">
                            <GeneratePdfButton href={exportEntriesPdf.url()} />
                        </div>

                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-start">
                                        <th className="px-4 py-2">
                                            {t('common.date')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.tank')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('inventory.quantity')} (L)
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.recorded_by')}
                                        </th>
                                        <th className="px-4 py-2">
                                            {t('common.notes')}
                                        </th>
                                        {auth.isAdmin && (
                                            <th className="px-4 py-2"></th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.data.map((entry) => (
                                        <tr key={entry.id} className="border-t">
                                            <td className="px-4 py-2">
                                                {formatDate(entry.date)}
                                            </td>
                                            <td className="px-4 py-2">
                                                {entry.tank?.fuel_type?.name} —{' '}
                                                {entry.tank?.name}
                                            </td>
                                            <td className="px-4 py-2">
                                                {formatNumber(
                                                    entry.quantity_liters,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                {entry.recorded_by?.name}
                                            </td>
                                            <td className="px-4 py-2">
                                                {entry.notes}
                                            </td>
                                            {auth.isAdmin && (
                                                <td className="space-x-2 px-4 py-2 text-end">
                                                    <Link
                                                        href={edit(entry.id)}
                                                        className="text-sm underline"
                                                    >
                                                        {t('common.edit')}
                                                    </Link>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            removeEntry(entry)
                                                        }
                                                    >
                                                        {t('common.delete')}
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {entries.data.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={auth.isAdmin ? 6 : 5}
                                                className="px-4 py-6 text-center text-muted-foreground"
                                            >
                                                {t('common.no_results')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <PaginationLinks links={entries.links} />
                    </div>
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
                                    {tanks.map((tank) => (
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
    breadcrumbs: [{ title: 'Inventory', href: index() }],
};
