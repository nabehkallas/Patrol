import { Head, Link, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { create, destroy, edit, index } from '@/routes/admin/users';
import type { ManagedUser } from '@/types';

type PageProps = {
    users: ManagedUser[];
};

export default function UsersIndex() {
    const { users } = usePage<PageProps>().props;
    const { t } = useTranslation();

    function remove(user: ManagedUser) {
        if (confirm(`${t('common.confirm_delete')} (${user.name})`)) {
            router.delete(destroy.url(user.id));
        }
    }

    return (
        <>
            <Head title={t('users.title')} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <Heading variant="small" title={t('users.title')} description={t('users.description')} />
                    <Link href={create()} className="bg-primary text-primary-foreground rounded-md px-4 py-2 text-sm font-medium">
                        {t('users.new')}
                    </Link>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-start">
                                <th className="px-4 py-2">{t('common.name')}</th>
                                <th className="px-4 py-2">{t('common.email')}</th>
                                <th className="px-4 py-2">{t('common.role')}</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id} className="border-t">
                                    <td className="px-4 py-2">{user.name}</td>
                                    <td className="px-4 py-2">{user.email}</td>
                                    <td className="px-4 py-2">
                                        <Badge variant={user.role === 'admin' ? 'default' : 'secondary'}>
                                            {user.role ? t(`roles.${user.role}` as TranslationKey) : ''}
                                        </Badge>
                                    </td>
                                    <td className="space-x-2 px-4 py-2 text-end">
                                        <Link href={edit(user.id)} className="text-sm underline">
                                            {t('common.edit')}
                                        </Link>
                                        <Button variant="ghost" size="sm" onClick={() => remove(user)}>
                                            {t('common.delete')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Employees', href: index() }],
};
