import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items = [],
    label,
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const { isMobile, setOpenMobile } = useSidebar();

    return (
        // Each group after the first is set apart by space and a thin divider; its header is a
        // bold, muted caption and its links are indented under it with a faint guide line.
        // (Letter-spacing only in LTR: it breaks the joins between Arabic letters.) A collapsed,
        // icon-only sidebar drops the indent and guide line so the icons stay centred.
        <SidebarGroup className="not-first:border-sidebar-border not-first:mt-3 not-first:border-t not-first:pt-3 px-2 py-0">
            <SidebarGroupLabel className="text-sidebar-foreground/55 mb-1 h-7 text-[0.7rem] font-bold ltr:uppercase ltr:tracking-wider">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu className="border-sidebar-border/80 ms-2 w-auto border-s ps-2 group-data-[collapsible=icon]:ms-0 group-data-[collapsible=icon]:border-s-0 group-data-[collapsible=icon]:ps-0">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link
                                href={item.href}
                                onClick={() => {
                                    if (isMobile) {
                                        setOpenMobile(false);
                                    }
                                }}
                            >
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
