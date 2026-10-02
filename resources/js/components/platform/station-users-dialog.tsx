import { BadgeCheck, Clock, LoaderCircle, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CopyButton } from '@/components/platform/copy-button';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/lib/i18n';
import { users as usersRoute } from '@/routes/platform/stations';

type StationUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'attendant' | null;
    verified: boolean;
};

type Station = { id: string; name: string };

/** Lists a station's accounts. Mounted only while open, so each opening fetches fresh data. */
export function StationUsersDialog({
    station,
    onClose,
}: {
    station: Station | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={station !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-2xl">
                {station && <UsersList station={station} />}
            </DialogContent>
        </Dialog>
    );
}

function UsersList({ station }: { station: Station }) {
    const { t } = useTranslation();
    const [users, setUsers] = useState<StationUser[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;

        fetch(usersRoute.url(station.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<{ users: StationUser[] }>;
            })
            .then((data) => !cancelled && setUsers(data.users))
            .catch(() => !cancelled && setFailed(true));

        return () => {
            cancelled = true;
        };
    }, [station.id]);

    const roleLabel = (role: StationUser['role']) =>
        role === 'admin'
            ? t('roles.admin')
            : role === 'attendant'
              ? t('roles.attendant')
              : '—';

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Users className="text-muted-foreground size-5" />
                    {t('platform.users.title').replace(
                        ':station',
                        station.name,
                    )}
                </DialogTitle>
                <DialogDescription>
                    {t('platform.users.description')}
                </DialogDescription>
            </DialogHeader>

            {failed ? (
                <p className="text-destructive py-6 text-center text-sm">
                    {t('platform.users.error')}
                </p>
            ) : users === null ? (
                <div className="text-muted-foreground flex items-center justify-center gap-2 py-8 text-sm">
                    <LoaderCircle className="size-4 animate-spin" />
                    {t('platform.users.loading')}
                </div>
            ) : users.length === 0 ? (
                <p className="text-muted-foreground py-6 text-center text-sm">
                    {t('platform.users.empty')}
                </p>
            ) : (
                <div className="max-h-[60vh] overflow-auto rounded-lg border">
                    <table
                        className="w-full text-sm"
                        data-test="station-users-table"
                    >
                        <thead className="bg-muted/60 sticky top-0">
                            <tr>
                                <th className="px-3 py-2.5 text-start font-medium">
                                    {t('platform.users.name')}
                                </th>
                                <th className="px-3 py-2.5 text-start font-medium">
                                    {t('common.email_address')}
                                </th>
                                <th className="px-3 py-2.5 text-start font-medium">
                                    {t('platform.users.role')}
                                </th>
                                <th className="px-3 py-2.5 text-start font-medium">
                                    {t('platform.users.verification')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id} className="border-t">
                                    <td className="px-3 py-2.5 font-medium">
                                        {user.name}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <div className="flex items-center gap-1">
                                            <bdi
                                                dir="ltr"
                                                className="break-all"
                                            >
                                                {user.email}
                                            </bdi>
                                            <CopyButton value={user.email} />
                                        </div>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <Badge
                                            variant={
                                                user.role === 'admin'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {roleLabel(user.role)}
                                        </Badge>
                                    </td>
                                    <td className="px-3 py-2.5">
                                        {user.verified ? (
                                            <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                                <BadgeCheck className="size-4" />
                                                {t('platform.users.verified')}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                                <Clock className="size-4" />
                                                {t('platform.users.pending')}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}
