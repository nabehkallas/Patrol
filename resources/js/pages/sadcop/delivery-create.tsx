import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useMemo } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
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
import { formatNumber, formatSyp } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/sadcop';
import { store } from '@/routes/sadcop/deliveries';
import type { SadcopTankOption } from '@/types';

type PageProps = {
    tanks: SadcopTankOption[];
    balance: number;
};

export default function SadcopDeliveryCreate() {
    const { tanks, balance } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        tank_id: String(tanks[0]?.id ?? ''),
        liters: '',
        price_per_liter: '',
        amount: '',
        notes: '',
    });

    const selectedTank = useMemo(
        () => tanks.find((tank) => tank.id === Number(form.data.tank_id)),
        [tanks, form.data.tank_id],
    );

    // Prefill the cost price whenever the tank changes; the user can still
    // overwrite it afterwards for that tank.
    useEffect(() => {
        if (!selectedTank) {
            return;
        }

        const defaultPrice = selectedTank.default_cost_price_per_liter;

        form.setData((data) => {
            const liters = parseFloat(data.liters);
            const computed = liters * defaultPrice;

            return {
                ...data,
                price_per_liter: String(defaultPrice),
                amount:
                    Number.isFinite(computed) && computed > 0
                        ? computed.toFixed(2)
                        : data.amount,
            };
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedTank?.id]);

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
            selectedTank &&
            parseFloat(form.data.liters) > selectedTank.remaining_liters
        ) {
            form.setError(
                'liters',
                `${t('common.exceeds_tank_capacity')} (${formatNumber(selectedTank.remaining_liters)} L)`,
            );

            return;
        }

        form.post(store.url());
    }

    return (
        <>
            <Head title={t('sadcop.record_delivery')} />

            <div className="max-w-xl space-y-6">
                <Heading
                    variant="small"
                    title={t('sadcop.record_delivery')}
                    description={t('sadcop.delivery_description')}
                />

                <Card className="max-w-xs">
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            {t('sadcop.current_balance')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-2xl font-semibold">
                        {formatSyp(balance)}
                    </CardContent>
                </Card>

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="tank_id">{t('common.tank')}</Label>
                        <Select
                            value={String(form.data.tank_id)}
                            onValueChange={(value) =>
                                form.setData('tank_id', value)
                            }
                            name="tank_id"
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

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="liters">
                                {t('common.liters')}
                                {selectedTank && (
                                    <span className="ms-2 text-xs font-normal text-muted-foreground">
                                        ({t('common.max')}:{' '}
                                        {formatNumber(
                                            selectedTank.remaining_liters,
                                        )}{' '}
                                        L)
                                    </span>
                                )}
                            </Label>
                            <Input
                                id="liters"
                                type="number"
                                step="0.001"
                                min="0"
                                max={selectedTank?.remaining_liters}
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
                            <InputError message={form.errors.price_per_liter} />
                        </div>
                    </div>

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
                        {t('sadcop.save_delivery')}
                    </Button>
                </form>
            </div>
        </>
    );
}

SadcopDeliveryCreate.layout = {
    breadcrumbs: [
        { title: 'Sadcop', href: index() },
        { title: 'Record delivery', href: '' },
    ],
};
