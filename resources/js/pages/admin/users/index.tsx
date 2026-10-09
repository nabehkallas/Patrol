import { Head, Link, router, usePage } from '@inertiajs/react';
import { Copy, KeyRound, MoreHorizontal, UserCheck, UserX } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateTime } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import {
    create,
    destroy,
    edit,
    index,
    resetPassword,
    toggleDisabled,
} from '@/routes/admin/users';
import type { ManagedUser } from '@/types';

type PageProps = {
    users: ManagedUser[];
    /** Flashed once after a password reset: the temporary password to pass on. */
    resetCredentials: { name: string; email: string; password: string } | null;
};

type Pending = { kind: 'reset' | 'toggle'; user: ManagedUser } | null;

export default function UsersIndex() {
    const { users, resetCredentials } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const [pending, setPending] = useState<Pending>(null);
    const [copied, setCopied] = useState(false);

    function confirmPending() {
        if (!pending) {
            return;
        }

        if (pending.kind === 'reset') {
            router.post(
                resetPassword.url(pending.user.id),
                {},
                { preserveScroll: true },
            );
        } else {
            router.patch(
                toggleDisabled.url(pending.user.id),
                {},
                { preserveScroll: true },
            );
        }

        setPending(null);
    }

    function copyPassword(password: string) {
        void navigator.clipboard?.writeText(password).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    return (
        <>
            <Head title={t('users.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title={t('users.title')}
                        description={t('users.description')}
                    />
                    <Link
                        href={create()}
                        className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium"
                    >
                        {t('users.new')}
                    </Link>
                </div>

                {resetCredentials && (
                    <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700/60 dark:bg-amber-950/40">
                        <p className="font-semibold">
                            {t('users.reset_done_title', {
                                name: resetCredentials.name,
                            })}
                        </p>
                        <p className="text-muted-foreground">
                            {t('users.reset_done_body')}
                        </p>
                        <div className="flex flex-wrap items-center gap-3">
                            <bdi
                                dir="ltr"
                                className="bg-background rounded-md border px-3 py-1.5 font-mono text-base tracking-wide"
                            >
                                {resetCredentials.password}
                            </bdi>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    copyPassword(resetCredentials.password)
                                }
                            >
                                <Copy />
                                {copied ? t('users.copied') : t('users.copy')}
                            </Button>
                        </div>
                    </div>
                )}

                <div className="table-stack overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-3">
                                    {t('common.name')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.email')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('common.role')}
                                </th>
                                <th className="px-4 py-3">
                                    {t('users.last_login')}
                                </th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr
                                    key={user.id}
                                    className={
                                        user.disabled
                                            ? 'text-muted-foreground border-t'
                                            : 'border-t'
                                    }
                                >
                                    <td className="px-4 py-3">
                                        {user.name}
                                        {user.is_me && (
                                            <span className="text-muted-foreground ms-1 text-xs">
                                                ({t('users.you')})
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{user.email}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <Badge
                                                variant={
                                                    user.role === 'admin'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {user.role
                                                    ? t(
                                                          `roles.${user.role}` as TranslationKey,
                                                      )
                                                    : ''}
                                            </Badge>
                                            {user.disabled && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-red-300 text-red-700 dark:border-red-800 dark:text-red-400"
                                                >
                                                    {t('users.disabled')}
                                                </Badge>
                                            )}
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {user.last_login_at
                                            ? formatDateTime(user.last_login_at)
                                            : t('users.never')}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <div className="flex items-center justify-end gap-1">
                                            <RowActions
                                                edit={edit(user.id).url}
                                                remove={
                                                    user.is_me
                                                        ? undefined
                                                        : destroy.url(user.id)
                                                }
                                            />
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        title={t('users.more')}
                                                        aria-label={t(
                                                            'users.more',
                                                        )}
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setPending({
                                                                kind: 'reset',
                                                                user,
                                                            })
                                                        }
                                                    >
                                                        <KeyRound />
                                                        {t(
                                                            'users.reset_password',
                                                        )}
                                                    </DropdownMenuItem>
                                                    {!user.is_me && (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setPending({
                                                                    kind: 'toggle',
                                                                    user,
                                                                })
                                                            }
                                                        >
                                                            {user.disabled ? (
                                                                <UserCheck />
                                                            ) : (
                                                                <UserX />
                                                            )}
                                                            {user.disabled
                                                                ? t(
                                                                      'users.enable',
                                                                  )
                                                                : t(
                                                                      'users.disable',
                                                                  )}
                                                        </DropdownMenuItem>
                                                    )}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Dialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogTitle>
                        {pending?.kind === 'reset'
                            ? t('users.reset_password')
                            : pending?.user.disabled
                              ? t('users.enable')
                              : t('users.disable')}
                        {pending && ` — ${pending.user.name}`}
                    </DialogTitle>
                    <DialogDescription>
                        {pending?.kind === 'reset'
                            ? t('users.reset_confirm')
                            : pending?.user.disabled
                              ? t('users.enable_confirm')
                              : t('users.disable_confirm')}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            variant={
                                pending?.kind === 'toggle' &&
                                !pending.user.disabled
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={confirmPending}
                        >
                            {pending?.kind === 'reset'
                                ? t('users.reset_password')
                                : pending?.user.disabled
                                  ? t('users.enable')
                                  : t('users.disable')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'nav.employees', href: index() }],
};
