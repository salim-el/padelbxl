import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';

const LANGUES = [
    ['fr', 'FR', 'Français'],
    ['nl', 'NL', 'Nederlands'],
    ['en', 'EN', 'English'],
] as const;

// Choix de la langue : gardé dans la session, et dans le compte quand le joueur est connecté.
export default function LanguageSwitcher({ className = '' }: { className?: string }) {
    const { locale } = usePage<SharedData>().props;

    return (
        <div role="group" aria-label="Langue · Taal · Language" className={`flex gap-1 text-xs font-semibold ${className}`}>
            {LANGUES.map(([code, court, nom]) => (
                <button
                    key={code}
                    type="button"
                    lang={code}
                    title={nom}
                    aria-label={nom}
                    aria-pressed={locale === code}
                    onClick={() => router.post(route('locale.update'), { locale: code }, { preserveScroll: true })}
                    className={`rounded px-2 py-1 transition focus-visible:ring-2 focus-visible:ring-[#C8E64A] focus-visible:outline-none ${
                        locale === code ? 'bg-[#C8E64A] text-[#0E1B2C]' : 'opacity-70 hover:opacity-100'
                    }`}
                >
                    {court}
                </button>
            ))}
        </div>
    );
}
