import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Plus, Star } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { currencyName, formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    index as currenciesIndex,
    primary,
    store,
    update,
} from '@/routes/currencies';
import type { StationCurrency } from '@/types';

type Row = StationCurrency & { rate_to_usd: number; has_rate: boolean };

/** Common currencies offered as presets; anything else can be added as a custom code. */
const PRESETS: {
    code: string;
    name: string;
    symbol: string;
    decimals: number;
}[] = [
    { code: 'USD', name: 'US Dollar', symbol: '$', decimals: 2 },
    { code: 'EUR', name: 'Euro', symbol: '€', decimals: 2 },
    { code: 'SYP', name: 'Syrian Pound', symbol: 'ل.س', decimals: 0 },
    { code: 'TRY', name: 'Turkish Lira', symbol: '₺', decimals: 2 },
    { code: 'GBP', name: 'British Pound', symbol: '£', decimals: 2 },
    { code: 'AED', name: 'UAE Dirham', symbol: 'د.إ', decimals: 2 },
    { code: 'SAR', name: 'Saudi Riyal', symbol: 'ر.س', decimals: 2 },
    { code: 'JOD', name: 'Jordanian Dinar', symbol: 'د.أ', decimals: 3 },
    { code: 'LBP', name: 'Lebanese Pound', symbol: 'ل.ل', decimals: 0 },
    { code: 'IQD', name: 'Iraqi Dinar', symbol: 'ع.د', decimals: 0 },
    { code: 'EGP', name: 'Egyptian Pound', symbol: 'ج.م', decimals: 2 },
    { code: 'KWD', name: 'Kuwaiti Dinar', symbol: 'د.ك', decimals: 3 },
    { code: 'QAR', name: 'Qatari Riyal', symbol: 'ر.ق', decimals: 2 },
    { code: 'JPY', name: 'Japanese Yen', symbol: '¥', decimals: 0 },
    { code: 'CNY', name: 'Chinese Yuan', symbol: '¥', decimals: 2 },
    { code: 'RUB', name: 'Russian Ruble', symbol: '₽', decimals: 2 },
];

const CUSTOM = '__custom';

function AddCurrencyForm({ existing }: { existing: string[] }) {
    const { t } = useTranslation();
    const available = PRESETS.filter((p) => !existing.includes(p.code));
    const [choice, setChoice] = useState<string>(available[0]?.code ?? CUSTOM);

    const presetValues = (code: string) => {
        const preset = PRESETS.find((p) => p.code === code);

        return preset
            ? {
                  code: preset.code,
                  name: preset.name,
                  symbol: preset.symbol,
                  decimals: String(preset.decimals),
              }
            : { code: '', name: '', symbol: '', decimals: '2' };
    };

    const form = useForm({ ...presetValues(choice), rate_to_usd: '' });

    function pick(value: string) {
        setChoice(value);
        form.setData({
            ...presetValues(value),
            rate_to_usd: form.data.rate_to_usd,
        });
        form.clearErrors();
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                const next = PRESETS.find(
                    (p) =>
                        !existing.includes(p.code) && p.code !== form.data.code,
                );
                setChoice(next?.code ?? CUSTOM);
                form.setData({
                    ...presetValues(next?.code ?? CUSTOM),
                    rate_to_usd: '',
                });
            },
        });
    }

    const custom = choice === CUSTOM;
    const isUsd = form.data.code.toUpperCase() === 'USD';

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-lg border p-4"
            data-test="add-currency-form"
        >
            <p className="font-medium">{t('settings.currencies.add_title')}</p>

            <div className="grid gap-2">
                <Label>{t('settings.currencies.currency')}</Label>
                <Select value={choice} onValueChange={pick}>
                    <SelectTrigger
                        className="w-full sm:w-72"
                        data-test="add-currency-select"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {available.map((p) => (
                            <SelectItem key={p.code} value={p.code}>
                                {p.code} — {currencyName(p.code, p.name)}
                            </SelectItem>
                        ))}
                        <SelectItem value={CUSTOM}>
                            {t('settings.currencies.custom')}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor="cur_code">
                        {t('settings.currencies.code')}
                    </Label>
                    <Input
                        id="cur_code"
                        value={form.data.code}
                        readOnly={!custom}
                        maxLength={10}
                        placeholder="ABC"
                        onChange={(e) =>
                            form.setData('code', e.target.value.toUpperCase())
                        }
                    />
                    <InputError message={form.errors.code} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="cur_name">
                        {t('settings.currencies.name')}
                    </Label>
                    <Input
                        id="cur_name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="cur_symbol">
                        {t('settings.currencies.symbol')}
                    </Label>
                    <Input
                        id="cur_symbol"
                        value={form.data.symbol}
                        maxLength={8}
                        onChange={(e) => form.setData('symbol', e.target.value)}
                    />
                    <InputError message={form.errors.symbol} />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor="cur_decimals">
                        {t('settings.currencies.decimals')}
                    </Label>
                    <Input
                        id="cur_decimals"
                        type="number"
                        min={0}
                        max={4}
                        value={form.data.decimals}
                        onChange={(e) =>
                            form.setData('decimals', e.target.value)
                        }
                    />
                    <InputError message={form.errors.decimals} />
                </div>
                {!isUsd && (
                    <div className="grid gap-2 sm:col-span-3">
                        <Label htmlFor="cur_rate">
                            {t('settings.currencies.rate_label').replace(
                                ':code',
                                form.data.code || '…',
                            )}
                        </Label>
                        <Input
                            id="cur_rate"
                            type="number"
                            step="any"
                            min="0"
                            className="sm:w-60"
                            value={form.data.rate_to_usd}
                            onChange={(e) =>
                                form.setData('rate_to_usd', e.target.value)
                            }
                            placeholder={t('common.for_example', {
                                value: '0.92',
                            })}
                        />
                        <InputError message={form.errors.rate_to_usd} />
                    </div>
                )}
            </div>

            <Button
                type="submit"
                disabled={form.processing}
                data-test="add-currency-submit"
            >
                <Plus className="size-4" />
                {t('settings.currencies.add_button')}
            </Button>
        </form>
    );
}

export default function Currencies() {
    const { stationCurrencies, errors } = usePage<{
        stationCurrencies: Row[];
        errors: Record<string, string>;
    }>().props;
    const { t } = useTranslation();

    const toggleActive = (row: Row, active: boolean) =>
        router.patch(
            update.url(row.code),
            {
                name: row.name,
                symbol: row.symbol,
                decimals: row.decimals,
                is_active: active,
            },
            { preserveScroll: true },
        );

    const makePrimary = (row: Row) =>
        router.post(primary.url(row.code), {}, { preserveScroll: true });

    return (
        <>
            <Head title={t('settings.currencies.title')} />
            <h1 className="sr-only">{t('settings.currencies.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.currencies.title')}
                    description={t('settings.currencies.description')}
                />

                <InputError message={errors.primary ?? errors.is_active} />

                <div
                    className="divide-y rounded-lg border"
                    data-test="currency-list"
                >
                    {stationCurrencies.map((row) => (
                        <div
                            key={row.code}
                            className={cn(
                                'flex flex-wrap items-center gap-3 p-4',
                                !row.is_active && 'opacity-60',
                            )}
                            data-test={`currency-row-${row.code}`}
                        >
                            <div className="bg-muted flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-semibold">
                                {row.symbol || row.code.slice(0, 2)}
                            </div>
                            <div className="min-w-40 flex-1">
                                <div className="flex items-center gap-2 font-medium">
                                    <span dir="ltr">{row.code}</span>
                                    {row.is_primary && (
                                        <Badge className="gap-1 bg-amber-500 text-white">
                                            <Star className="size-3" />
                                            {t('settings.currencies.primary')}
                                        </Badge>
                                    )}
                                </div>
                                <div className="text-muted-foreground text-sm">
                                    {currencyName(row.code, row.name)}
                                </div>
                            </div>
                            <div
                                className="text-muted-foreground text-sm"
                                dir="ltr"
                            >
                                {row.code === 'USD'
                                    ? t('settings.currencies.pivot')
                                    : row.has_rate
                                      ? `1 USD = ${formatNumber(row.rate_to_usd, 4)} ${row.code}`
                                      : t('settings.currencies.no_rate')}
                            </div>
                            <div className="ms-auto flex items-center gap-3">
                                {!row.is_primary && row.is_active && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => makePrimary(row)}
                                        data-test={`make-primary-${row.code}`}
                                    >
                                        <Check className="size-4" />
                                        {t('settings.currencies.make_primary')}
                                    </Button>
                                )}
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={row.is_active}
                                        disabled={row.is_primary}
                                        onCheckedChange={(v) =>
                                            toggleActive(row, v === true)
                                        }
                                    />
                                    {t('settings.currencies.active')}
                                </label>
                            </div>
                        </div>
                    ))}
                </div>

                <p className="text-muted-foreground text-sm">
                    {t('settings.currencies.rates_hint')}
                </p>

                <AddCurrencyForm
                    existing={stationCurrencies.map((c) => c.code)}
                />
            </div>
        </>
    );
}

Currencies.layout = {
    breadcrumbs: [
        {
            title: 'settings.currencies.title',
            href: currenciesIndex(),
        },
    ],
};
