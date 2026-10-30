import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

// Formulaire sur fond crème, photo du terrain arcade à droite (en bandeau sur mobile).
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh bg-[#F7F6F1] text-[#0E1B2C] lg:grid-cols-2">
            <aside className="relative h-48 overflow-hidden bg-[#0E1B2C] lg:sticky lg:top-0 lg:order-2 lg:h-svh">
                <img
                    src="/images/connexion-1000.webp"
                    srcSet="/images/connexion-500.webp 500w, /images/connexion-1000.webp 1000w"
                    sizes="(min-width: 1024px) 50vw, 100vw"
                    alt=""
                    className="absolute inset-0 h-full w-full object-cover"
                />
                <div className="absolute inset-0 bg-gradient-to-t from-[#0E1B2C] via-[#0E1B2C]/40 to-transparent" aria-hidden />
                <div className="absolute bottom-0 p-6 text-[#F7F6F1] lg:p-12">
                    <p className="font-mono text-xs tracking-widest text-[#C8E64A] uppercase">Arena Padel Club · Anderlecht</p>
                    <p className="mt-2 text-2xl leading-tight font-extrabold uppercase lg:text-4xl">
                        Le padel,
                        <br />
                        <span className="text-[#C8E64A]">version arcade.</span>
                    </p>
                </div>
            </aside>

            <main className="flex flex-col items-center justify-center p-6 md:p-10">
                <div className="w-full max-w-sm">
                    <div className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-4">
                            <Link href={route('home')} aria-label="PadelBxl, retour à l’accueil">
                                <img src="/images/logo-fond-clair.webp" alt="PadelBxl" className="h-[114px] w-auto" />
                            </Link>
                            <div className="space-y-2 text-center">
                                <h1 className="text-2xl font-extrabold uppercase">{title}</h1>
                                <p className="text-center text-sm text-[#5F6B78]">{description}</p>
                            </div>
                        </div>
                        {children}
                    </div>
                    <Link
                        href={route('home')}
                        className="mt-8 flex items-center justify-center gap-1.5 text-sm font-semibold text-[#1D5C7A] hover:underline"
                    >
                        <ArrowLeft className="size-4" aria-hidden /> Retour à l’accueil
                    </Link>
                </div>
            </main>
        </div>
    );
}
