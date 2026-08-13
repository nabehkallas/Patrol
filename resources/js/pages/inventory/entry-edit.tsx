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
import { update } from '@/routes/inventory/entries';

type Entry = {
    id: number;
    tank_id: number;
    date: string;
    quantity_liters: string;
    notes: string | null;
};

type TankOption = {
    id: number;
    name: string;
    fuel_type_id: number;
    fuel_type_name: string;
};

type PageProps = {
    entry: Entry;
    tanks: TankOption[];
};

export default function InventoryEntryEdit() {
    const { entry, tanks } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        tank_id: String(entry.tank_id),
        date: entry.date,
        quantity_liters: entry.quantity_liters,
        notes: entry.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(entry.id));
    }

    return (
        <>
            <Head title={t('inventory.edit_entry')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('inventory.edit_entry')} />

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
                                form.setData('quantity_liters', e.target.value)
                            }
                        />
                        <InputError message={form.errors.quantity_liters} />
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

InventoryEntryEdit.layout = {
    breadcrumbs: [
        { title: 'Inventory', href: index() },
        { title: 'Edit entry', href: '' },
    ],
};
