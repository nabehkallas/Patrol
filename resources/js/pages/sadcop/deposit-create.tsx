import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/sadcop';
import { store } from '@/routes/sadcop/deposits';

export default function SadcopDepositCreate() {
    const { t } = useTranslation();

    const form = useForm({
        amount: '',
        notes: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url());
    }

    return (
        <>
            <Head title={t('sadcop.transfer_money')} />

            <div className="max-w-xl space-y-6">
                <Heading
                    variant="small"
                    title={t('sadcop.transfer_money')}
                    description={t('sadcop.transfer_description')}
                />

                <form onSubmit={submit} className="space-y-6">
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
                        {t('sadcop.save_transfer')}
                    </Button>
                </form>
            </div>
        </>
    );
}

SadcopDepositCreate.layout = {
    breadcrumbs: [
        { title: 'Sadcop', href: index() },
        { title: 'Transfer money', href: '' },
    ],
};
