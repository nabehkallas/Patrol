import { router, useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useCurrencies, useCurrencyName } from '@/components/currency-options';
import { EntryDateInput } from '@/components/entry-date-input';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney, formatShortDate, todayInStation } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { destroy, store } from '@/routes/opening-cash';

export type OpeningCash = {
    id: number;
    currency: string;
    amount: string;
    date: string;
    notes: string | null;
};

/**
 * Settings > "Cash when you started using the app" (admins): the cash each box held before the
 * station recorded anything, one amount per currency, counted as money in on its date (never
 * income). Saving again replaces it. Not for cash already recorded as a deposit or income.
 */
export function OpeningCashCard({ openings }: { openings: OpeningCash[] }) {
    const { t } = useTranslation();
    const { active } = useCurrencies();
    const currencyName = useCurrencyName();
    const [editing, setEditing] = useState<string | null>(null);
    const form = useForm({ currency: '', amount: '', date: '', notes: '' });
    const byCurrency = new Map(openings.map((o) => [o.currency, o]));

    function open(currency: string) {
        const saved = byCurrency.get(currency);
        form.clearErrors();
        form.setData({
            currency,
            amount: saved ? String(parseFloat(saved.amount)) : '',
            date: saved?.date.slice(0, 10) ?? todayInStation(),
            notes: saved?.notes ?? '',
        });
        setEditing(currency);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    }

    function remove(opening: OpeningCash) {
        if (confirm(t('cash_box.opening_cash_remove_confirm'))) {
            router.delete(destroy.url(opening.id), { preserveScroll: true });
        }
    }

    return (
        <div className="space-y-4" data-test="opening-cash">
            <div
                role="note"
                className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
            >
                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                <p>{t('opening_cash.warning')}</p>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {active.map(({ code }) => {
                    const saved = byCurrency.get(code);

                    return (
                        <div
                            key={code}
                            className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2"
                            data-test={`opening-cash-${code}`}
                        >
                            <div className="min-w-0">
                                <div className="text-muted-foreground text-xs">
                                    {t('cash_source.box', {
                                        box: currencyName(code),
                                    })}
                                </div>
                                <bdi
                                    dir="ltr"
                                    className="block font-medium tabular-nums"
                                >
                                    {saved
                                        ? formatMoney(saved.amount, code)
                                        : '—'}
                                </bdi>
                                {saved && (
                                    <div className="text-muted-foreground text-xs">
                                        {formatShortDate(saved.date)}
                                    </div>
                                )}
                            </div>
                            <div className="flex shrink-0 gap-1">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => open(code)}
                                >
                                    {saved
                                        ? t('common.edit')
                                        : t('cash_box.opening_cash_set')}
                                </Button>
                                {saved && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() => remove(saved)}
                                    >
                                        {t('common.delete')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(isOpen) => !isOpen && setEditing(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogTitle>
                        {t('opening_cash.title')} —{' '}
                        {editing ? currencyName(editing) : ''}
                    </DialogTitle>
                    <DialogDescription>
                        {t('cash_box.opening_cash_hint')}
                    </DialogDescription>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="opening_amount">
                                    {t('common.amount')} ({editing})
                                </Label>
                                <MoneyInput
                                    id="opening_amount"
                                    value={form.data.amount}
                                    onChange={(value) =>
                                        form.setData('amount', value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="opening_date">
                                    {t('common.date')}
                                </Label>
                                <EntryDateInput
                                    id="opening_date"
                                    value={form.data.date}
                                    onChange={(e) =>
                                        form.setData('date', e.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.date} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="opening_notes">
                                {t('common.notes')}
                            </Label>
                            <Input
                                id="opening_notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </div>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.processing}>
                                {t('common.save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
