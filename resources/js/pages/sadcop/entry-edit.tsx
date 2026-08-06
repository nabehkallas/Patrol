import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
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
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/sadcop';
import { update } from '@/routes/sadcop/entries';
import type { SadcopLedgerEntryType, SadcopTankOption } from '@/types';

type EntryProps = {
    id: number;
    type: SadcopLedgerEntryType;
    amount: string;
    liters: string | null;
    price_per_liter: string | null;
    occurred_at: string;
    notes: string | null;
    tank_id: number | null;
};

type PageProps = {
    entry: EntryProps;
    tanks: SadcopTankOption[];
    balance: number;
};

export default function SadcopEntryEdit() {
    const { entry, tanks } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const isDelivery = entry.type === 'delivery';
    const isOpening = entry.type === 'opening';

    const form = useForm({
        tank_id: String(entry.tank_id ?? tanks[0]?.id ?? ''),
        liters: entry.liters ?? '',
        price_per_liter: entry.price_per_liter ?? '',
        amount: entry.amount,
        occurred_at: entry.occurred_at.slice(0, 10),
        notes: entry.notes ?? '',
    });

    const selectedTank = useMemo(
        () => tanks.find((tank) => tank.id === Number(form.data.tank_id)),
        [tanks, form.data.tank_id],
    );

    function handleLitersOrPriceChange(liters: string, pricePerLiter: string) {
        const computed = parseFloat(liters) * parseFloat(pricePerLiter);

        form.setData((data) => ({
            ...data,
            liters,
            price_per_liter: pricePerLiter,
            amount:
                Number.isFinite(computed) && computed > 0
                    ? computed.toFixed(2)
                    : data.amount,
        }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            isDelivery &&
            selectedTank &&
            parseFloat(form.data.liters) >
                selectedTank.remaining_liters + parseFloat(entry.liters ?? '0')
        ) {
            form.setError(
                'liters',
                `${t('common.exceeds_tank_capacity')} (${formatNumber(selectedTank.remaining_liters + parseFloat(entry.liters ?? '0'))} L)`,
            );

            return;
        }

        form.patch(update.url(entry.id));
    }

    return (
        <>
            <Head title={t('sadcop.edit_entry')} />

            <div className="max-w-xl space-y-6">
                <Heading variant="small" title={t('sadcop.edit_entry')} />

                <form onSubmit={submit} className="space-y-6">
                    {isDelivery && (
                        <div className="grid gap-2">
                            <Label htmlFor="tank_id">{t('common.tank')}</Label>
                            <Select
                                value={String(form.data.tank_id)}
                                onValueChange={(value) =>
                                    form.setData('tank_id', value)
                                }
                            >
                                <SelectTrigger id="tank_id" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {tanks.map((tank) => (
                                        <SelectItem
                                            key={tank.id}
                                            value={String(tank.id)}
                                        >
                                            {tank.fuel_type_name} — {tank.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.tank_id} />
                        </div>
                    )}

                    {isDelivery && (
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="liters">
                                    {t('common.liters')}
                                </Label>
                                <Input
                                    id="liters"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.data.liters}
                                    onChange={(e) =>
                                        handleLitersOrPriceChange(
                                            e.target.value,
                                            form.data.price_per_liter,
                                        )
                                    }
                                />
                                <InputError message={form.errors.liters} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="price_per_liter">
                                    {t('sadcop.cost_price_per_liter')}
                                </Label>
                                <MoneyInput
                                    id="price_per_liter"
                                    value={form.data.price_per_liter}
                                    onChange={(value) =>
                                        handleLitersOrPriceChange(
                                            form.data.liters,
                                            value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.price_per_liter}
                                />
                            </div>
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="amount">{t('sadcop.amount_syp')}</Label>
                        <MoneyInput
                            id="amount"
                            value={form.data.amount}
                            onChange={(value) => form.setData('amount', value)}
                            required
                        />
                        <InputError message={form.errors.amount} />
                    </div>

                    {!isOpening && (
                        <div className="grid gap-2">
                            <Label htmlFor="occurred_at">
                                {t('common.date')}
                            </Label>
                            <Input
                                id="occurred_at"
                                type="date"
                                value={form.data.occurred_at}
                                onChange={(e) =>
                                    form.setData('occurred_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.occurred_at} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="notes">{t('common.notes')}</Label>
                        <Textarea
                            id="notes"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

SadcopEntryEdit.layout = {
    breadcrumbs: [
        { title: 'Sadcop', href: index() },
        { title: 'Edit entry', href: '' },
    ],
};
