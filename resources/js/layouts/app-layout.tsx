import { FlashMessages } from '@/components/flash-messages';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface AppLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default ({ children, breadcrumbs, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
        {/* Pages privées (espace joueur, gérant) : hors de Google (T17). */}
        <Head>
            <meta name="robots" content="noindex" />
        </Head>
        <FlashMessages />
        {children}
    </AppLayoutTemplate>
);
