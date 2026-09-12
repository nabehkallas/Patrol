import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { useTranslation } from '@/lib/i18n';
import { index } from '@/routes/inventory';
import { update } from '@/routes/tank-top-ups';

type TopUp = {
    id: number;
    tank_id: number;
    date: string;
    liters: string;
    notes: string | null;
};

type TankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
};

type PageProps = {
    topUp: TopUp;
    tanks: TankOption[];
};

export default function TopUpEdit() {
    const { topUp, tanks } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        tank_id: String(topUp.tank_id),
        date: topUp.date,
        liters: topUp.liters,
        notes: topUp.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(topUp.id));
    }

    return (
        <>
            <Head title={t('inventory.edit_topup')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('inventory.edit_topup')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="tank_id">{t('common.tank')}</Label>
                        <Select
                            value={form.data.tank_id}
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

                    <div className="grid gap-2">
                        <Label htmlFor="date">{t('common.date')}</Label>
                        <Input
                            id="date"
                            type="date"
                            value={form.data.date}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                        />
                        <InputError message={form.errors.date} />
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
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('common.save_changes')}
                    </Button>
                </form>
            </div>
        </>
    );
}

TopUpEdit.layout = {
    breadcrumbs: [
        { title: 'Inventory', href: index() },
        { title: 'Edit top-up', href: '' },
    ],
};
