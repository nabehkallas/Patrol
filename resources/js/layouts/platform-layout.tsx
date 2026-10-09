import { Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    LogOut,
    PanelsTopLeft,
    Moon,
    ShieldCheck,
    Sun,
    UserCog,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageMenu } from '@/components/language-menu';
import { AccountDialog } from '@/components/platform/account-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/lib/i18n';
import { logout } from '@/routes';
import { home } from '@/routes/platform';
import { edit as editLanding } from '@/routes/platform/landing';
import type { Auth } from '@/types';

/** Shell for the platform (super admin) panel: header with language, theme and account menu. */
export default function PlatformLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const [accountOpen, setAccountOpen] = useState(false);

    const initials = auth.user.name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <div className="bg-background min-h-screen">
            <header className="bg-sidebar text-sidebar-foreground border-sidebar-border sticky top-0 z-30 border-b">
                <div className="page-container flex h-16 items-center gap-3">
                    <Link href={home()} className="flex items-center gap-2.5">
                        <div className="bg-sidebar-primary text-sidebar-primary-foreground flex size-9 items-center justify-center rounded-lg">
                            <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
                        </div>
                        <div className="leading-tight">
                            <div className="text-sm font-semibold">Patrol</div>
                            <div className="text-sidebar-foreground/60 flex items-center gap-1 text-xs">
                                <ShieldCheck className="size-3" />
                                {t('platform.panel')}
                            </div>
                        </div>
                    </Link>

                    <div className="ms-auto flex items-center gap-1.5">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="hover:bg-sidebar-accent hover:text-sidebar-accent-foreground gap-1.5"
                            asChild
                        >
                            <Link
                                href={editLanding()}
                                data-test="platform-landing-link"
                            >
                                <PanelsTopLeft className="size-4" />
                                <span className="hidden sm:inline">
                                    {t('platform.landing.nav_link')}
                                </span>
                            </Link>
                        </Button>
                        <LanguageMenu
                            className="hover:bg-sidebar-accent hover:text-sidebar-accent-foreground h-8 font-medium"
                            data-test="platform-locale-toggle"
                        />

                        <Button
                            variant="ghost"
                            size="icon"
                            className="hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                            onClick={() =>
                                updateAppearance(
                                    resolvedAppearance === 'dark'
                                        ? 'light'
                                        : 'dark',
                                )
                            }
                            aria-label={t('platform.toggle_theme')}
                        >
                            {resolvedAppearance === 'dark' ? (
                                <Sun className="size-4" />
                            ) : (
                                <Moon className="size-4" />
                            )}
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="hover:bg-sidebar-accent hover:text-sidebar-accent-foreground gap-2 px-2"
                                    data-test="platform-account-menu"
                                >
                                    <span className="bg-sidebar-primary text-sidebar-primary-foreground flex size-7 items-center justify-center rounded-full text-xs font-semibold">
                                        {initials}
                                    </span>
                                    <span className="hidden text-sm sm:inline">
                                        {auth.user.name}
                                    </span>
                                    <ChevronDown className="size-4 opacity-60" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-64">
                                <DropdownMenuLabel className="font-normal">
                                    <div className="text-sm font-medium">
                                        {auth.user.name}
                                    </div>
                                    <div
                                        className="text-muted-foreground truncate text-xs"
                                        dir="ltr"
                                    >
                                        {auth.user.email}
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => setAccountOpen(true)}
                                    data-test="platform-account-settings"
                                >
                                    <UserCog className="size-4" />
                                    {t('platform.account.menu')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => {
                                        router.flushAll();
                                        router.post(logout.url());
                                    }}
                                >
                                    <LogOut className="size-4" />
                                    {t('platform.logout')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </header>

            <main className="page-container py-8">{children}</main>

            <AccountDialog
                open={accountOpen}
                onOpenChange={setAccountOpen}
                user={auth.user}
            />
        </div>
    );
}
