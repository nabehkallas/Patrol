import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';

export function NavUser() {
    const { auth } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const { direction } = useLocale();
    const inSidebar = !isMobile && state !== 'collapsed';

    if (!auth.user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent group"
                            data-test="sidebar-menu-button"
                        >
                            <UserInfo user={auth.user} />
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        // Expanded: opens upward, anchored to the sidebar's outer edge and a bit
                        // narrower than the button, so it keeps a clear gap from the sidebar's
                        // scrollbar (on the content-facing side in both directions).
                        // Collapsed: opens sideways towards the content; mobile: below.
                        className={cn(
                            'rounded-lg',
                            inSidebar
                                ? 'w-[calc(var(--radix-dropdown-menu-trigger-width)-1.75rem)]'
                                : 'min-w-56',
                        )}
                        align={inSidebar ? 'start' : 'end'}
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? direction === 'rtl'
                                      ? 'left'
                                      : 'right'
                                  : 'top'
                        }
                        sideOffset={12}
                        collisionPadding={8}
                    >
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
