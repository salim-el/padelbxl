import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    FileText,
    Gavel,
    Inbox,
    LayoutDashboard,
    LifeBuoy,
    Mail,
    QrCode,
    ScrollText,
    Shield,
    Store,
    Ticket,
    UserCog,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Planning', url: '/planning', icon: CalendarDays },
    { title: 'Mes réservations', url: '/bookings', icon: Ticket },
    { title: 'Parties ouvertes', url: '/open-matches', icon: Users },
    { title: 'Mes demandes', url: '/disputes', icon: LifeBuoy },
];

const staffNavItems: NavItem[] = [
    { title: 'Tableau de bord', url: '/admin', icon: LayoutDashboard },
    { title: 'Comptoir', url: '/admin/counter', icon: Store },
    { title: 'Demandes', url: '/admin/disputes', icon: Inbox },
    { title: 'Contestations', url: '/admin/appeals', icon: Gavel },
    { title: 'Journal', url: '/admin/journal', icon: ScrollText },
    { title: 'Borne d’accès', url: '/kiosk', icon: QrCode },
];

// F35 : réservé à l'administrateur.
const adminNavItems: NavItem[] = [{ title: 'Personnel', url: '/admin/staff', icon: UserCog }];

// Ouvertes dans un nouvel onglet (NavFooter) : on garde l'écran en cours.
const footerNavItems: NavItem[] = [
    { title: 'Conditions générales', url: '/conditions-generales', icon: FileText },
    { title: 'Confidentialité', url: '/confidentialite', icon: Shield },
    { title: 'Contact', url: '/contact', icon: Mail },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const isStaff = ['manager', 'admin'].includes(String(auth.user?.role));
    const items = [...mainNavItems, ...(isStaff ? staffNavItems : []), ...(auth.user?.role === 'admin' ? adminNavItems : [])];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/planning" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
