import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/inventory';
import { update } from '@/routes/tank-transfers';

type Transfer = {
    id: number;
    liters: string;
    notes: string | null;
    date: string;
    from_tank_name: string | null;
    to_tank_name: string | null;
};

type PageProps = {
    transfer: Transfer;
};

export default function TransferEdit() {
    const { transfer } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        liters: transfer.liters,
        notes: transfer.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(transfer.id));
    }

    return (
        <>
            <Head title={t('inventory.edit_transfer')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('inventory.edit_transfer')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label>{t('inventory.from_tank')}</Label>
                        <Input value={transfer.from_tank_name ?? ''} disabled />
                    </div>

                    <div className="grid gap-2">
                        <Label>{t('inventory.to_tank')}</Label>
                        <Input value={transfer.to_tank_name ?? ''} disabled />
                    </div>

                    <div className="grid gap-2">
                        <Label>{t('common.date')}</Label>
                        <Input value={formatDate(transfer.date)} disabled />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="liters">{t('common.liters')}</Label>
                        <Input
                            id="liters"
                            type="number"
                            step="0.001"
                            min="0"
                            value={form.data.liters}
                            onChange={(e) =>
                                form.setData('liters', e.target.value)
                            }
                        />
                        <InputError message={form.errors.liters} />
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
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

TransferEdit.layout = {
    breadcrumbs: [
        { title: 'Inventory', href: index() },
        { title: 'Edit transfer', href: '' },
    ],
};
