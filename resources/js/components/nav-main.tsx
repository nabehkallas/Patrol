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
        // Each section opens with a tinted badge header; links are larger, semibold and dark,
        // and the active one is a high-contrast navy pill with an orange ring. Font sizes are in
        // rem so Arabic/Urdu keep their deliberately larger text. Letter-spacing and uppercase
        // only in LTR: letter-spacing breaks the joins between Arabic letters.
        <SidebarGroup className="not-first:mt-6 mt-2 px-2 py-0">
            <SidebarGroupLabel className="mb-2 h-auto rounded-lg border border-slate-300/60 bg-slate-200/70 px-3 py-1.5 text-xs font-bold text-slate-900 md:text-sm ltr:uppercase ltr:tracking-wide dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-100">
                {label}
            </SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            className="h-auto px-3 py-2 text-[0.95rem] font-semibold text-slate-800 data-[active=true]:font-bold data-[active=true]:text-white data-[active=true]:shadow-md data-[active=true]:ring-2 dark:text-slate-200 dark:data-[active=true]:text-white [&>svg]:size-5 group-data-[collapsible=icon]:[&>svg]:size-4"
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
