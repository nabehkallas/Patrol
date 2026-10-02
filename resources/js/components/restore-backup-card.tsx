import { useForm } from '@inertiajs/react';
import { FileUp, ShieldAlert, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
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
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { restore } from '@/routes/data';

/**
 * Settings > Station Data: upload a file made with "Download backup" and, after confirming
 * with the current password, replace the station's data with it.
 */
export function RestoreBackupCard() {
    const { t } = useTranslation();
    const fileInput = useRef<HTMLInputElement>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const form = useForm<{ backup: File | null; password: string }>({
        backup: null,
        password: '',
    });

    function chooseFile(file: File | null) {
        form.setData('backup', file);
        form.clearErrors('backup');
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(restore.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setConfirmOpen(false);
                form.reset();

                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
            onError: (errors) => {
                form.reset('password');

                // A problem with the file itself is shown on the card, not in the dialog.
                if (errors.backup) {
                    setConfirmOpen(false);
                }
            },
        });
    }

    const sizeKb = form.data.backup
        ? Math.max(1, Math.round(form.data.backup.size / 1024))
        : 0;

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <div className="space-y-0.5">
                <p className="font-medium">
                    {t('settings.data.restore_title')}
                </p>
                <p className="text-muted-foreground text-sm">
                    {t('settings.data.restore_description')}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <input
                    ref={fileInput}
                    id="backup_file"
                    type="file"
                    accept=".sqlite,.db"
                    className="sr-only"
                    onChange={(e) => chooseFile(e.target.files?.[0] ?? null)}
                    data-test="restore-file-input"
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => fileInput.current?.click()}
                >
                    <FileUp className="size-4" />
                    {t('settings.data.restore_choose')}
                </Button>
                <span
                    className="text-muted-foreground min-w-0 truncate text-sm"
                    dir="ltr"
                >
                    {form.data.backup
                        ? `${form.data.backup.name} (${sizeKb} KB)`
                        : t('settings.data.restore_no_file')}
                </span>
            </div>
            <InputError message={form.errors.backup} />

            <Button
                type="button"
                disabled={!form.data.backup}
                onClick={() => setConfirmOpen(true)}
                data-test="restore-open-confirm"
            >
                <Upload className="size-4" />
                {t('settings.data.restore_button')}
            </Button>

            <Dialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    setConfirmOpen(open);

                    if (!open) {
                        form.reset('password');
                        form.clearErrors('password');
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submit} className="space-y-5">
                        <DialogHeader>
                            <div className="mb-2 flex size-11 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400">
                                <ShieldAlert className="size-5" />
                            </div>
                            <DialogTitle>
                                {t('settings.data.restore_confirm_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('settings.data.restore_confirm_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2">
                            <Label htmlFor="restore_password">
                                {t('settings.data.restore_password')}
                            </Label>
                            <PasswordInput
                                id="restore_password"
                                autoComplete="current-password"
                                autoFocus
                                value={form.data.password}
                                onChange={(e) =>
                                    form.setData('password', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.password} />
                        </div>

                        {form.progress && (
                            <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                                <div
                                    className="bg-primary h-full transition-all"
                                    style={{
                                        width: `${form.progress.percentage ?? 0}%`,
                                    }}
                                />
                            </div>
                        )}

                        <DialogFooter className="gap-2 sm:gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConfirmOpen(false)}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                className="bg-amber-500 text-white hover:bg-amber-600"
                                disabled={
                                    form.processing || form.data.password === ''
                                }
                                data-test="restore-submit"
                            >
                                {form.processing
                                    ? t('settings.data.restore_working')
                                    : t('settings.data.restore_confirm_button')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
