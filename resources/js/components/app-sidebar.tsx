import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CircleDollarSign,
    Container,
    Cylinder,
    Droplets,
    Banknote,
    Calculator,
    Contact,
    Fuel,
    Gauge,
    PiggyBank,
    Receipt,
    ShoppingBag,
    Truck,
    TrendingUp,
    ScrollText,
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
import { index as auditLogIndex } from '@/routes/admin/audit-log';
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
import { index as shopIndex } from '@/routes/shop';
import { index as statisticsIndex } from '@/routes/statistics';
import { tankVolume as tankVolumeIndex } from '@/routes/tools';
import { index as transactionsIndex } from '@/routes/transactions';
import type { Auth, NavItem } from '@/types';

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { t } = useTranslation();

    const operationsNavItems: NavItem[] = [
        { title: t('nav.cash_box'), href: cashBoxIndex(), icon: Wallet },
        {
            title: t('nav.pump_counters'),
            href: pumpCountersIndex(),
            icon: Gauge,
        },
        { title: t('nav.inventory'), href: inventoryIndex(), icon: Cylinder },
        { title: t('nav.shop'), href: shopIndex(), icon: ShoppingBag },
        { title: t('nav.sadcop'), href: sadcopIndex(), icon: Truck },
    ];

    const accountsNavItems: NavItem[] = [
        { title: t('nav.debts'), href: debtsIndex(), icon: Banknote },
        { title: t('nav.debtors'), href: debtorsIndex(), icon: Contact },
        {
            title: t('nav.transactions'),
            href: transactionsIndex(),
            icon: Receipt,
        },
    ];

    // Admin only, here and on the server (role:admin on every route behind these links).
    const managementNavItems: NavItem[] = [
        { title: t('nav.earnings'), href: earningsIndex(), icon: PiggyBank },
        {
            title: t('nav.statistics'),
            href: statisticsIndex(),
            icon: TrendingUp,
        },
        {
            title: t('nav.fuel_prices'),
            href: fuelPricesIndex(),
            icon: CircleDollarSign,
        },
        {
            title: t('nav.exchange_rates'),
            href: exchangeRatesIndex(),
            icon: ArrowLeftRight,
        },
        { title: t('nav.employees'), href: usersIndex(), icon: Users },
        { title: t('nav.audit_log'), href: auditLogIndex(), icon: ScrollText },
    ];

    const stationSetupNavItems: NavItem[] = [
        { title: t('nav.tanks'), href: tanksIndex(), icon: Container },
        { title: t('nav.fuel_pumps'), href: fuelPumpsIndex(), icon: Fuel },
        { title: t('nav.fuel_types'), href: fuelTypesIndex(), icon: Droplets },
        {
            title: t('nav.tank_volume_calculator'),
            href: tankVolumeIndex(),
            icon: Calculator,
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

            {/* Scrolls by wheel, touch and keyboard but shows no scrollbar of its own, so the page
                has a single visible scrollbar. */}
            <SidebarContent className="[scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <NavMain
                    items={operationsNavItems}
                    label={t('nav.operations')}
                />
                <NavMain
                    items={accountsNavItems}
                    label={t('nav.accounts_debts')}
                />
                {auth.isAdmin && (
                    <>
                        <NavMain
                            items={managementNavItems}
                            label={t('nav.management_reports')}
                        />
                        <NavMain
                            items={stationSetupNavItems}
                            label={t('nav.station_setup')}
                        />
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
