import { useForm } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ConfirmTone = 'success' | 'warning' | 'danger';

export type PendingAction = {
    title: string;
    message: string;
    confirmLabel: string;
    tone: ConfirmTone;
    icon?: LucideIcon;
    url: string;
    method: 'post' | 'delete';
    /** A date to send with the confirmation (e.g. a subscription's end); empty clears it. */
    dateField?: { name: string; label: string; value: string };
};

const TONES: Record<ConfirmTone, { icon: string; button: string }> = {
    success: {
        icon: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        button: 'bg-emerald-600 text-white hover:bg-emerald-700',
    },
    warning: {
        icon: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        button: 'bg-amber-500 text-white hover:bg-amber-600',
    },
    danger: {
        icon: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
        button: 'bg-rose-600 text-white hover:bg-rose-700',
    },
};

/**
 * Security confirmation for every station action: says exactly what will happen and only
 * proceeds once the platform admin re-enters their current password (checked server-side).
 */
export function ConfirmActionDialog({
    action,
    onClose,
}: {
    action: PendingAction | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={action !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {/* Mounted only while open, so the password field always starts empty. */}
                {action && <ConfirmForm action={action} onClose={onClose} />}
            </DialogContent>
        </Dialog>
    );
}

function ConfirmForm({
    action,
    onClose,
}: {
    action: PendingAction;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const form = useForm<Record<string, string>>({
        current_password: '',
        ...(action.dateField
            ? { [action.dateField.name]: action.dateField.value }
            : {}),
    });
    const tone = TONES[action.tone];
    const Icon = action.icon ?? ShieldAlert;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit(action.method, action.url, {
            errorBag: 'confirm',
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => form.reset('current_password'),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-5">
            <DialogHeader>
                <div
                    className={cn(
                        'mb-2 flex size-11 items-center justify-center rounded-full',
                        tone.icon,
                    )}
                >
                    <Icon className="size-5" />
                </div>
                <DialogTitle>{action.title}</DialogTitle>
                <DialogDescription>{action.message}</DialogDescription>
            </DialogHeader>

            {action.dateField && (
                <div className="grid gap-2">
                    <Label htmlFor="confirm_date">
                        {action.dateField.label}
                    </Label>
                    <Input
                        id="confirm_date"
                        type="date"
                        value={form.data[action.dateField.name] ?? ''}
                        onChange={(e) =>
                            form.setData(action.dateField!.name, e.target.value)
                        }
                    />
                    <InputError message={form.errors[action.dateField.name]} />
                </div>
            )}

            <div className="grid gap-2">
                <Label htmlFor="confirm_current_password">
                    {t('platform.confirm.password_label')}
                </Label>
                <PasswordInput
                    id="confirm_current_password"
                    autoComplete="current-password"
                    autoFocus
                    value={form.data.current_password}
                    onChange={(e) =>
                        form.setData('current_password', e.target.value)
                    }
                    required
                />
                <InputError message={form.errors.current_password} />
            </div>

            <DialogFooter className="gap-2 sm:gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <Button
                    type="submit"
                    className={tone.button}
                    disabled={
                        form.processing || form.data.current_password === ''
                    }
                    data-test="confirm-action-submit"
                >
                    {action.confirmLabel}
                </Button>
            </DialogFooter>
        </form>
    );
}
