import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { index, store } from '@/routes/debtors';

export default function DebtorCreate() {
    const { t } = useTranslation();

    const form = useForm({
        name: '',
        phone: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url());
    }

    return (
        <>
            <Head title={t('debtors.new')} />

            <div className="max-w-xl space-y-6">
                <Heading
                    variant="small"
                    title={t('debtors.new')}
                    description={t('debtors.description')}
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="phone">{t('debtors.phone')}</Label>
                        <Input
                            id="phone"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                        />
                        <InputError message={form.errors.phone} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('debtors.new')}
                    </Button>
                </form>
            </div>
        </>
    );
}

DebtorCreate.layout = {
    breadcrumbs: [
        { title: 'Debtors', href: index() },
        { title: 'New debtor', href: '' },
    ],
};
