import { Download, LoaderCircle, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
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
import { backup } from '@/routes/data';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** "attachment; filename=name.sqlite" -> "name.sqlite" */
function fileNameFrom(disposition: string | null): string {
    const match = disposition?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);

    return match ? decodeURIComponent(match[1]) : 'station-backup.sqlite';
}

/**
 * Settings > Station Data: the backup holds all of the station's data, so downloading it asks
 * for the account password first. The server checks it before sending the file; the request
 * runs in the background so a wrong password can be shown right here in the dialog.
 */
export function DownloadBackupButton() {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [working, setWorking] = useState(false);

    function close(next: boolean) {
        setOpen(next);

        if (!next) {
            setPassword('');
            setError(null);
        }
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        setWorking(true);
        setError(null);

        try {
            const response = await fetch(backup.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ password }),
            });

            if (response.status === 422) {
                const data = await response.json();
                setError(
                    data.errors?.password?.[0] ??
                        t('settings.data.backup_failed'),
                );
                setPassword('');

                return;
            }

            if (response.status === 429) {
                setError(t('settings.data.backup_too_many'));

                return;
            }

            if (!response.ok) {
                setError(t('settings.data.backup_failed'));

                return;
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = fileNameFrom(
                response.headers.get('Content-Disposition'),
            );
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 10_000);
            close(false);
        } catch {
            setError(t('settings.data.backup_failed'));
        } finally {
            setWorking(false);
        }
    }

    return (
        <>
            <Button
                variant="outline"
                onClick={() => setOpen(true)}
                data-test="backup-open"
            >
                <Download className="size-4" />
                {t('settings.data.backup_button')}
            </Button>

            <Dialog open={open} onOpenChange={close}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submit} className="space-y-5">
                        <DialogHeader>
                            <div className="bg-muted mb-2 flex size-11 items-center justify-center rounded-full">
                                <ShieldCheck className="size-5" />
                            </div>
                            <DialogTitle>
                                {t('settings.data.backup_confirm_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('settings.data.backup_confirm_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2">
                            <Label htmlFor="backup_password">
                                {t('settings.data.restore_password')}
                            </Label>
                            <PasswordInput
                                id="backup_password"
                                autoComplete="current-password"
                                autoFocus
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                required
                            />
                            <InputError message={error ?? undefined} />
                        </div>

                        <DialogFooter className="gap-2 sm:gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => close(false)}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={working || password === ''}
                                data-test="backup-submit"
                            >
                                {working ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <Download className="size-4" />
                                )}
                                {t('settings.data.backup_button')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
