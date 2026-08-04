import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { index, store } from '@/routes/admin/users';
import type { UserRole } from '@/types';

type PageProps = {
    roles: UserRole[];
};

export default function UserCreate() {
    const { roles } = usePage<PageProps>().props;
    const { t } = useTranslation();

    const form = useForm({
        name: '',
        email: '',
        password: '',
        role: roles.includes('attendant') ? 'attendant' : roles[0],
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url());
    }

    return (
        <>
            <Head title={t('users.new')} />

            <div className="max-w-md space-y-6">
                <Heading variant="small" title={t('users.new')} />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('common.email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">{t('common.password')}</Label>
                        <Input
                            id="password"
                            type="password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">{t('common.role')}</Label>
                        <Select value={form.data.role} onValueChange={(value) => form.setData('role', value as UserRole)}>
                            <SelectTrigger id="role" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem key={role} value={role}>
                                        {t(`roles.${role}` as TranslationKey)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.role} />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {t('users.create')}
                    </Button>
                </form>
            </div>
        </>
    );
}

UserCreate.layout = {
    breadcrumbs: [
        { title: 'Employees', href: index() },
        { title: 'New employee', href: '' },
    ],
};
