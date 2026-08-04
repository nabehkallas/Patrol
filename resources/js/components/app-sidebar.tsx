import { Link, usePage } from '@inertiajs/react';
import {
    Banknote,
    Calculator,
    Contact,
    Fuel,
    Gauge,
    PiggyBank,
    Receipt,
    Truck,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTranslation } from '@/lib/i18n';
import { index as earningsIndex } from '@/routes/admin/earnings';
import { index as exchangeRatesIndex } from '@/routes/admin/exchange-rates';
import { index as fuelPricesIndex } from '@/routes/admin/fuel-prices';
import { index as fuelPumpsIndex } from '@/routes/admin/fuel-pumps';
import { index as fuelTypesIndex } from '@/routes/admin/fuel-types';
import { index as tanksIndex } from '@/routes/admin/tanks';
import { index as usersIndex } from '@/routes/admin/users';
import { index as cashBoxIndex } from '@/routes/cash-box';
import { index as debtorsIndex } from '@/routes/debtors';
import { index as debtsIndex } from '@/routes/debts';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as pumpCountersIndex } from '@/routes/pump-counters';
import { index as sadcopIndex } from '@/routes/sadcop';
import { index as statisticsIndex } from '@/routes/statistics';
import { tankVolume as tankVolumeIndex } from '@/routes/tools';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();

    const mainNavItems: NavItem[] = [
        {
            title: t('nav.cash_box'),
            href: cashBoxIndex(),
            icon: Wallet,
        },
        {
            title: t('nav.inventory'),
            href: inventoryIndex(),
            icon: Gauge,
        },
        {
            title: t('nav.debts'),
            href: debtsIndex(),
            icon: Banknote,
        },
        {
            title: t('nav.sadcop'),
            href: sadcopIndex(),
            icon: Truck,
        },
        {
            title: t('nav.pump_counters'),
            href: pumpCountersIndex(),
            icon: Gauge,
        },
    ];

    const toolsReportsNavItems: NavItem[] = [
        {
            title: t('nav.tank_volume_calculator'),
            href: tankVolumeIndex(),
            icon: Calculator,
        },
        {
            title: t('nav.statistics'),
            href: statisticsIndex(),
            icon: TrendingUp,
        },
        {
            title: t('nav.transactions'),
            href: transactionsIndex(),
            icon: Receipt,
        },
    ];

    const adminNavItems: NavItem[] = [
        {
            title: t('nav.employees'),
            href: usersIndex(),
            icon: Users,
        },
        {
            title: t('nav.tanks'),
            href: tanksIndex(),
            icon: Gauge,
        },
        {
            title: t('nav.fuel_types'),
            href: fuelTypesIndex(),
            icon: Fuel,
        },
        {
            title: t('nav.fuel_prices'),
            href: fuelPricesIndex(),
            icon: Fuel,
        },
        {
            title: t('nav.exchange_rates'),
            href: exchangeRatesIndex(),
            icon: Gauge,
        },
        {
            title: t('nav.fuel_pumps'),
            href: fuelPumpsIndex(),
            icon: Fuel,
        },
        {
            title: t('nav.earnings'),
            href: earningsIndex(),
            icon: PiggyBank,
        },
        {
            title: t('nav.debtors'),
            href: debtorsIndex(),
            icon: Contact,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={cashBoxIndex()}>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavMain
                    items={toolsReportsNavItems}
                    label={t('nav.tools_reports')}
                />
                {auth.isAdmin && (
                    <NavMain items={adminNavItems} label={t('nav.admin')} />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
