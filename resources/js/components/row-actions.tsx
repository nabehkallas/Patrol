import { Link, router } from '@inertiajs/react';
import { ExternalLink, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/lib/i18n';

type Props = {
    /** A page to edit the row on, or a handler that opens an inline editor. */
    edit?: string | (() => void);
    /** The DELETE route for the row, or a handler that does the delete itself. */
    remove?: string | (() => void);
    /** Shown in the confirmation dialog in place of the generic question. */
    confirmMessage?: string;
    /**
     * The row was recorded by another screen and can only be changed there: a link to it
     * replaces the edit and delete buttons.
     */
    managedIn?: { label: string; href: string };
};

/**
 * The edit/delete buttons at the end of a table row, the same on every table: icon buttons
 * with a label for screen readers and on hover, and a delete that always asks first.
 */
export function RowActions({ edit, remove, confirmMessage, managedIn }: Props) {
    const { t } = useTranslation();
    const [confirming, setConfirming] = useState(false);

    if (managedIn) {
        return (
            <Link
                href={managedIn.href}
                className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 whitespace-nowrap text-xs"
            >
                {t('common.managed_in', { screen: managedIn.label })}
                <ExternalLink className="size-3 rtl:-scale-x-100" />
            </Link>
        );
    }

    function confirmDelete() {
        setConfirming(false);

        if (typeof remove === 'string') {
            router.delete(remove, { preserveScroll: true });
        } else {
            remove?.();
        }
    }

    const editButton = (
        <Button
            variant="ghost"
            size="icon"
            className="size-8"
            title={t('common.edit')}
            aria-label={t('common.edit')}
            asChild={typeof edit === 'string'}
            onClick={typeof edit === 'function' ? edit : undefined}
        >
            {typeof edit === 'string' ? (
                <Link href={edit}>
                    <Pencil />
                </Link>
            ) : (
                <Pencil />
            )}
        </Button>
    );

    return (
        <div className="flex items-center justify-end gap-1">
            {edit && editButton}
            {remove && (
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive size-8"
                        title={t('common.delete')}
                        aria-label={t('common.delete')}
                        onClick={() => setConfirming(true)}
                    >
                        <Trash2 />
                    </Button>
                    <Dialog open={confirming} onOpenChange={setConfirming}>
                        <DialogContent className="sm:max-w-md">
                            <DialogTitle>
                                {t('common.delete_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {confirmMessage ?? t('common.confirm_delete')}
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="outline">
                                        {t('common.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    onClick={confirmDelete}
                                >
                                    {t('common.delete')}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </>
            )}
        </div>
    );
}
