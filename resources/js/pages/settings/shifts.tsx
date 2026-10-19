import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Save, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { destroy, index as shiftsIndex, store, update } from '@/routes/shifts';

type ShiftRow = {
    id: number;
    position: number;
    name: string;
    custom_name: string | null;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    used: boolean;
};

/** Name and optional times: the same fields for a saved shift and a new one. */
function ShiftFields({
    idPrefix,
    data,
    errors,
    placeholder,
    onChange,
}: {
    idPrefix: string;
    data: { name: string; starts_at: string; ends_at: string };
    errors: Partial<Record<'name' | 'starts_at' | 'ends_at', string>>;
    placeholder: string;
    onChange: (field: 'name' | 'starts_at' | 'ends_at', value: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="grid min-w-0 flex-1 basis-64 gap-3 sm:grid-cols-[minmax(8rem,1fr)_minmax(6.5rem,8rem)_minmax(6.5rem,8rem)]">
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-name`}>
                    {t('settings.shifts.name')}
                </Label>
                <Input
                    id={`${idPrefix}-name`}
                    value={data.name}
                    maxLength={60}
                    placeholder={placeholder}
                    onChange={(e) => onChange('name', e.target.value)}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-start`}>
                    {t('settings.shifts.starts_at')}
                </Label>
                <Input
                    id={`${idPrefix}-start`}
                    type="time"
                    className="min-w-0"
                    value={data.starts_at}
                    onChange={(e) => onChange('starts_at', e.target.value)}
                />
                <InputError message={errors.starts_at} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor={`${idPrefix}-end`}>
                    {t('settings.shifts.ends_at')}
                </Label>
                <Input
                    id={`${idPrefix}-end`}
                    type="time"
                    className="min-w-0"
                    value={data.ends_at}
                    onChange={(e) => onChange('ends_at', e.target.value)}
                />
                <InputError message={errors.ends_at} />
            </div>
        </div>
    );
}

function ShiftItem({
    shift,
    onlyActive,
}: {
    shift: ShiftRow;
    onlyActive: boolean;
}) {
    const { t } = useTranslation();
    const form = useForm({
        name: shift.custom_name ?? '',
        starts_at: shift.starts_at ?? '',
        ends_at: shift.ends_at ?? '',
        is_active: shift.is_active,
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(shift.id), { preserveScroll: true });
    }

    function toggle(active: boolean) {
        form.setData('is_active', active);
        router.patch(
            update.url(shift.id),
            { ...form.data, is_active: active },
            { preserveScroll: true },
        );
    }

    return (
        <form
            onSubmit={save}
            className={cn(
                'flex flex-wrap items-start gap-4 p-4',
                !shift.is_active && 'opacity-60',
            )}
            data-test={`shift-row-${shift.position}`}
        >
            <div className="bg-muted flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-semibold">
                {shift.position}
            </div>
            <ShiftFields
                idPrefix={`shift-${shift.id}`}
                data={form.data}
                errors={form.errors}
                placeholder={t('Shift :number', { number: shift.position })}
                onChange={(field, value) => form.setData(field, value)}
            />
            <div className="flex w-full items-center justify-end gap-3">
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={shift.is_active}
                        disabled={shift.is_active && onlyActive}
                        onCheckedChange={(v) => toggle(v === true)}
                    />
                    {t('settings.shifts.active')}
                </label>
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={form.processing || !form.isDirty}
                >
                    <Save className="size-4" />
                    {t('common.save')}
                </Button>
                {!shift.used && !(shift.is_active && onlyActive) && (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="text-destructive"
                        aria-label={t('common.delete')}
                        onClick={() =>
                            router.delete(destroy.url(shift.id), {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Trash2 className="size-4" />
                    </Button>
                )}
            </div>
        </form>
    );
}

function AddShiftForm({ nextPosition }: { nextPosition: number }) {
    const { t } = useTranslation();
    const form = useForm({ name: '', starts_at: '', ends_at: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-lg border p-4"
            data-test="add-shift-form"
        >
            <p className="font-medium">{t('settings.shifts.add_title')}</p>
            <ShiftFields
                idPrefix="new-shift"
                data={form.data}
                errors={form.errors}
                placeholder={t('Shift :number', { number: nextPosition })}
                onChange={(field, value) => form.setData(field, value)}
            />
            <Button type="submit" disabled={form.processing}>
                <Plus className="size-4" />
                {t('settings.shifts.add_button')}
            </Button>
        </form>
    );
}

export default function Shifts() {
    const { shifts, errors } = usePage<{
        shifts: ShiftRow[];
        errors: Record<string, string>;
    }>().props;
    const { t } = useTranslation();
    const activeCount = shifts.filter((shift) => shift.is_active).length;

    return (
        <>
            <Head title={t('settings.shifts.title')} />
            <h1 className="sr-only">{t('settings.shifts.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.shifts.title')}
                    description={t('settings.shifts.description')}
                />

                <InputError message={errors.shift ?? errors.is_active} />

                <div
                    className="divide-y rounded-lg border"
                    data-test="shift-list"
                >
                    {shifts.map((shift) => (
                        <ShiftItem
                            key={`${shift.id}-${shift.is_active}`}
                            shift={shift}
                            onlyActive={activeCount <= 1}
                        />
                    ))}
                </div>

                <p className="text-muted-foreground text-sm">
                    {t('settings.shifts.hint')}
                </p>

                <AddShiftForm
                    nextPosition={
                        Math.max(0, ...shifts.map((shift) => shift.position)) +
                        1
                    }
                />
            </div>
        </>
    );
}

Shifts.layout = {
    breadcrumbs: [
        {
            title: 'settings.shifts.title',
            href: shiftsIndex(),
        },
    ],
};
