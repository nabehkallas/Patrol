import { useForm } from '@inertiajs/react';
import { LockIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
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
import { unlock } from '@/routes/statistics/annual';

export function AnnualUnlockDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const form = useForm({ password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(unlock.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
            onError: () => form.reset('password'),
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.reset();
                    form.clearErrors();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <div className="bg-muted mb-2 flex size-10 items-center justify-center rounded-full">
                            <LockIcon className="size-5" />
                        </div>
                        <DialogTitle>{t('statistics.tab_annual')}</DialogTitle>
                        <DialogDescription>
                            {t('statistics.annual_prompt')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="annual_password">
                            {t('statistics.security_code')}
                        </Label>
                        <Input
                            id="annual_password"
                            type="password"
                            autoComplete="off"
                            autoFocus
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        <InputError message={form.errors.password} />
                        <p className="text-muted-foreground text-xs">
                            {t('statistics.annual_prompt_hint')}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || form.data.password === ''
                            }
                        >
                            {t('statistics.annual_unlock')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
