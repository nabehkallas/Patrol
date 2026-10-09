import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

/** The logo and name at the top of the sidebar, with the signed-in station's name under it. */
export default function AppLogo() {
    const { tenant } = usePage<{ tenant?: { name: string } | null }>().props;

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <AppLogoIcon className="size-5 text-white dark:text-black" />
            </div>
            <div className="ms-1 grid flex-1 text-start text-sm">
                <span className="truncate font-semibold leading-tight">
                    Patrol
                </span>
                {tenant?.name && (
                    <span
                        className="text-muted-foreground truncate text-xs leading-tight"
                        title={tenant.name}
                    >
                        {tenant.name}
                    </span>
                )}
            </div>
        </>
    );
}
