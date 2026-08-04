import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { index, update } from '@/routes/debtors';
import type { Debtor } from '@/types';

type PageProps = {
    debtor: Debtor;
};

export default function DebtorEdit() {
    const { debtor } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        name: debtor.name,
        phone: debtor.phone ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(debtor.id));
    }

    return (
        <>
            <Head title={t('debtors.edit')} />

            <div className="max-w-xl space-y-6">
                <Heading variant="small" title={t('debtors.edit')} />

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
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

DebtorEdit.layout = {
    breadcrumbs: [
        { title: 'Debtors', href: index() },
        { title: 'Edit', href: '' },
    ],
};
