import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/lib/i18n';

/** The field the server answers with when the primary box would go negative (CashBox). */
export const CONFIRM_FIELD = 'confirm_negative_cash';

/**
 * The primary-currency box may go below zero (expenses are often entered before the day's
 * sales), but only once the user agrees: the server refuses first with a message under
 * CONFIRM_FIELD, this asks it, and "continue" sends the same form again with the flag.
 * Use `handle(errors, resend)` in a form's onError; render `dialog` once.
 */
export function useNegativeCashConfirm() {
    const { t } = useTranslation();
    const [pending, setPending] = useState<{
        message: string;
        resend: () => void;
    } | null>(null);

    function handle(
        errors: Record<string, string | undefined>,
        resend: () => void,
    ): boolean {
        const message = errors[CONFIRM_FIELD];

        if (!message) {
            return false;
        }

        setPending({ message, resend });

        return true;
    }

    const dialog = (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && setPending(null)}
        >
            <DialogContent
                className="sm:max-w-md"
                data-test="negative-cash-dialog"
            >
                <DialogTitle>{t('cash_source.negative_title')}</DialogTitle>
                <DialogDescription>{pending?.message}</DialogDescription>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => setPending(null)}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        onClick={() => {
                            const resend = pending?.resend;
                            setPending(null);
                            resend?.();
                        }}
                    >
                        {t('cash_source.negative_continue')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );

    return { handle, dialog };
}
