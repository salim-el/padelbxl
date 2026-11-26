import { PageHeader } from '@/components/page-header';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

type Entry = {
    id: number;
    at: string;
    log: string;
    event: string | null;
    description: string;
    causer: string | null;
    subject: string | null;
    details: string | null;
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };

const FILTERS: [string | null, string][] = [
    [null, 'Tout'],
    ['staff', 'Actions du personnel'],
    ['api', 'Jetons API'],
];
const tab = 'rounded-full border px-3 py-1 text-sm';

export default function Journal({ entries, log }: { entries: Page<Entry>; log: string | null }) {
    const t = useT();

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('Tableau de bord'), href: '/admin' },
                { title: t('Journal'), href: '/admin/journal' },
            ]}
        >
            <Head title={t('Journal des actions')} />
            <div className="flex flex-col gap-6 p-4">
                <PageHeader eyebrow={t('Espace gérant')} title={t('Journal des actions')} />
                <p className="text-muted-foreground text-sm">
                    {t(
                        'Qui a fait quoi, et quand. Les entrées sont gardées un an, puis effacées automatiquement. Le journal ne peut pas être modifié depuis l’application.',
                    )}
                </p>

                <nav aria-label={t('Filtrer le journal')} className="flex flex-wrap gap-2">
                    {FILTERS.map(([value, label]) => (
                        <Link
                            key={label}
                            href={value ? `/admin/journal?log=${value}` : '/admin/journal'}
                            aria-current={log === value ? 'page' : undefined}
                            className={`${tab} ${log === value ? 'bg-[#0E1B2C] text-[#F7F6F1]' : 'bg-card'}`}
                        >
                            {t(label)}
                        </Link>
                    ))}
                </nav>

                <div className="bg-card relative overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">{t('Journal des actions, :n entrées', { n: entries.total })}</caption>
                        <thead>
                            <tr className="border-b text-left">
                                <th className="p-2">{t('Date')}</th>
                                <th className="p-2">{t('Auteur')}</th>
                                <th className="p-2">{t('Action')}</th>
                                <th className="p-2">{t('Concerne')}</th>
                                <th className="p-2">{t('Détail')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-3 text-center">
                                        {t('Aucune action enregistrée pour l’instant.')}
                                    </td>
                                </tr>
                            )}
                            {entries.data.map((e) => (
                                <tr key={e.id} className="border-b last:border-0">
                                    <td className="p-2 whitespace-nowrap">{e.at}</td>
                                    <td className="p-2">{e.causer ?? t('Système')}</td>
                                    <td className="p-2">{e.description}</td>
                                    <td className="p-2">{e.subject ?? '—'}</td>
                                    <td className="p-2 whitespace-nowrap">{e.details ?? ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {entries.last_page > 1 && (
                    <nav aria-label={t('Pages du journal')} className="flex items-center justify-end gap-3 text-sm">
                        {entries.prev_page_url ? (
                            <Link href={entries.prev_page_url} preserveScroll className="underline">
                                {t('‹ Précédent')}
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">{t('‹ Précédent')}</span>
                        )}
                        <span>{t('Page :current sur :last', { current: entries.current_page, last: entries.last_page })}</span>
                        {entries.next_page_url ? (
                            <Link href={entries.next_page_url} preserveScroll className="underline">
                                {t('Suivant ›')}
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">{t('Suivant ›')}</span>
                        )}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
