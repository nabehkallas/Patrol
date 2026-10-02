import { useForm } from '@inertiajs/react';
import { KeyRound, UserRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { password as passwordRoute, update } from '@/routes/platform/account';
import type { User } from '@/types';

type Tab = 'profile' | 'password';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user: User;
};

/** The platform admin's own name/email and password, each confirmed with the current password. */
export function AccountDialog({ open, onOpenChange, user }: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                {/* Mounted only while open, so every opening starts from a clean form. */}
                <AccountForms onOpenChange={onOpenChange} user={user} />
            </DialogContent>
        </Dialog>
    );
}

function AccountForms({ onOpenChange, user }: Omit<Props, 'open'>) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<Tab>('profile');

    const profile = useForm({
        name: user.name,
        email: user.email,
        current_password: '',
    });
    const password = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function saveProfile(event: FormEvent) {
        event.preventDefault();
        profile.patch(update.url(), {
            errorBag: 'profile',
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => profile.reset('current_password'),
        });
    }

    function savePassword(event: FormEvent) {
        event.preventDefault();
        password.put(passwordRoute.url(), {
            errorBag: 'password',
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: () => password.reset(),
        });
    }

    const tabs: { value: Tab; label: string; icon: typeof UserRound }[] = [
        {
            value: 'profile',
            label: t('platform.account.profile_tab'),
            icon: UserRound,
        },
        {
            value: 'password',
            label: t('platform.account.password_tab'),
            icon: KeyRound,
        },
    ];

    return (
        <>
            <DialogHeader>
                <DialogTitle>{t('platform.account.title')}</DialogTitle>
                <DialogDescription>
                    {t('platform.account.description')}
                </DialogDescription>
            </DialogHeader>

            <div className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1">
                {tabs.map(({ value, label, icon: Icon }) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => setTab(value)}
                        className={cn(
                            'flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                            tab === value
                                ? 'bg-background shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'profile' ? (
                <form onSubmit={saveProfile} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="account_name">
                            {t('platform.account.name')}
                        </Label>
                        <Input
                            id="account_name"
                            value={profile.data.name}
                            onChange={(e) =>
                                profile.setData('name', e.target.value)
                            }
                            required
                        />
                        <InputError message={profile.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="account_email">
                            {t('common.email_address')}
                        </Label>
                        <Input
                            id="account_email"
                            type="email"
                            dir="ltr"
                            value={profile.data.email}
                            onChange={(e) =>
                                profile.setData('email', e.target.value)
                            }
                            required
                        />
                        <InputError message={profile.errors.email} />
                        {profile.data.email !== user.email && (
                            <p className="text-muted-foreground text-xs">
                                {t('platform.account.email_change_note')}
                            </p>
                        )}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="account_profile_current">
                            {t('platform.account.current_password')}
                        </Label>
                        <PasswordInput
                            id="account_profile_current"
                            autoComplete="current-password"
                            value={profile.data.current_password}
                            onChange={(e) =>
                                profile.setData(
                                    'current_password',
                                    e.target.value,
                                )
                            }
                            required
                        />
                        <InputError message={profile.errors.current_password} />
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={profile.processing}
                        data-test="platform-save-profile"
                    >
                        {t('platform.account.save_profile')}
                    </Button>
                </form>
            ) : (
                <form onSubmit={savePassword} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="account_password_current">
                            {t('platform.account.current_password')}
                        </Label>
                        <PasswordInput
                            id="account_password_current"
                            autoComplete="current-password"
                            value={password.data.current_password}
                            onChange={(e) =>
                                password.setData(
                                    'current_password',
                                    e.target.value,
                                )
                            }
                            required
                        />
                        <InputError
                            message={password.errors.current_password}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="account_password_new">
                            {t('platform.account.new_password')}
                        </Label>
                        <PasswordInput
                            id="account_password_new"
                            autoComplete="new-password"
                            value={password.data.password}
                            onChange={(e) =>
                                password.setData('password', e.target.value)
                            }
                            required
                        />
                        <InputError message={password.errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="account_password_confirm">
                            {t('common.password_confirmation')}
                        </Label>
                        <PasswordInput
                            id="account_password_confirm"
                            autoComplete="new-password"
                            value={password.data.password_confirmation}
                            onChange={(e) =>
                                password.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                            required
                        />
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={password.processing}
                        data-test="platform-save-password"
                    >
                        {t('platform.account.save_password')}
                    </Button>
                </form>
            )}
        </>
    );
}
