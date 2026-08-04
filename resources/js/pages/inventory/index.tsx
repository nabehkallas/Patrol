import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { GeneratePdfButton } from '@/components/generate-pdf-button';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PaginationLinks from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { store as storeTopUp } from '@/routes/tank-top-ups';
import type {
    InventoryEntry,
    Paginated,
    TankSummary,
    TankTopUp,
} from '@/types';

type PageProps = {
    tanks: TankSummary[];
    entries: Paginated<InventoryEntry>;
    topUps: TankTopUp[];
    topUpDate: string;
};

type Tab = 'amounts' | 'actual';

export default function InventoryIndex() {
    const { tanks, entries, topUps, topUpDate } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [activeTab, setActiveTab] = useState<Tab>('amounts');
    const [topUpLiters, setTopUpLiters] = useState<Record<number, string>>({});
    const [topUpErrors, setTopUpErrors] = useState<Record<number, string>>({});

    const form = useForm({
        tank_id: String(tanks[0]?.id ?? ''),
        date: new Date().toISOString().slice(0, 10),
        quantity_liters: '',
        notes: '',
    });

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

    function handleTopUpDateChange(newDate: string) {
        router.get(
            index(),
            { topup_date: newDate },
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
                <Heading
                    variant="small"
                    title={t('inventory.title')}
                    description={t('inventory.description')}
                />

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

                        <div className="space-y-3">
                            <div className="flex items-center gap-4">
                                <h3 className="font-semibold">
                                    {t('inventory.top_up_history')}
                                </h3>
                                <Input
                                    type="date"
                                    value={topUpDate}
                                    onChange={(e) =>
                                        handleTopUpDateChange(e.target.value)
                                    }
                                    className="w-auto"
                                />
                                <GeneratePdfButton
                                    href={exportTopupsPdf.url({
                                        query: { topup_date: topUpDate },
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
                                        </tr>
                                    ))}
                                    {entries.data.length === 0 && (
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

                        <PaginationLinks links={entries.links} />
                    </div>
                )}
            </div>
        </>
    );
}

InventoryIndex.layout = {
    breadcrumbs: [{ title: 'Inventory', href: index() }],
};
