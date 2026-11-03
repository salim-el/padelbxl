import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// Écran réservé au personnel : la double authentification protège le tableau de bord, le journal et la borne.
export default function TwoFactor({ enabled, qr, secret }: { enabled: boolean; qr: string | null; secret: string | null }) {
    const { data, setData, post, delete: destroy, processing, errors, reset } = useForm({ code: '', password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onFinish: () => reset('code', 'password') };
        if (enabled) destroy('/settings/two-factor', options);
        else post('/settings/two-factor', options);
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Double authentification', href: '/settings/two-factor' }]}>
            <Head title="Double authentification" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Double authentification"
                        description="Obligatoire pour le personnel : en plus du mot de passe, un code à six chiffres change toutes les 30 secondes sur votre téléphone."
                    />

                    {enabled ? (
                        <p className="rounded-lg bg-[#EEF4D6] px-3 py-2 text-sm text-[#4E6600]">
                            Activée. Le code vous est demandé à chaque connexion.
                        </p>
                    ) : (
                        <ol className="list-decimal space-y-4 pl-5 text-sm">
                            <li>
                                Installez une application d’authentification sur votre téléphone (Microsoft Authenticator, Google Authenticator,
                                FreeOTP…).
                            </li>
                            <li>
                                Scannez ce code avec l’application.
                                {qr && (
                                    <img
                                        src={qr}
                                        alt="Code QR à scanner avec l’application d’authentification"
                                        className="mt-2 size-48 rounded-lg border bg-white p-2"
                                    />
                                )}
                                <span className="mt-2 block">
                                    Pas d’appareil photo ? Saisissez cette clé à la main :{' '}
                                    <code className="bg-muted rounded px-1.5 py-0.5 font-mono break-all select-all">{secret}</code>
                                </span>
                            </li>
                            <li>Entrez le code affiché par l’application pour confirmer.</li>
                        </ol>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        {enabled && (
                            <div className="grid gap-2">
                                <Label htmlFor="password">Mot de passe actuel</Label>
                                <PasswordInput
                                    id="password"
                                    autoComplete="current-password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                <InputError message={errors.password} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="code">{enabled ? 'Code actuel, pour désactiver' : 'Code à six chiffres'}</Label>
                            <Input
                                id="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                pattern="[0-9]{6}"
                                maxLength={6}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                className="w-40 font-mono tracking-widest"
                            />
                            <InputError message={errors.code} />
                        </div>
                        <Button disabled={processing} variant={enabled ? 'outline' : 'default'}>
                            {enabled ? 'Désactiver' : 'Activer'}
                        </Button>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
