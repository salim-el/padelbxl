import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profil',
        href: '/settings/profile',
    },
];

export default function Profile({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    const t = useT();
    const { auth } = usePage<SharedData>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: auth.user.email,
        current_password: '',
    });
    const emailChanged = data.email.trim().toLowerCase() !== auth.user.email.toLowerCase();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Profil')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('Informations du profil')} description={t('Modifiez votre nom et votre adresse e-mail')} />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Nom complet')}</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder={t('Prénom et nom')}
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('Adresse e-mail')}</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder={t('Adresse e-mail')}
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {emailChanged && (
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">{t('Mot de passe actuel')}</Label>

                                <Input
                                    id="current_password"
                                    type="password"
                                    className="mt-1 block w-full"
                                    value={data.current_password}
                                    onChange={(e) => setData('current_password', e.target.value)}
                                    required
                                    autoComplete="current-password"
                                    aria-describedby="current_password_hint"
                                />
                                <p id="current_password_hint" className="text-muted-foreground text-sm">
                                    {t('Pour changer d’adresse e-mail, confirmez votre mot de passe.')}
                                </p>

                                <InputError className="mt-2" message={errors.current_password} />
                            </div>
                        )}

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div>
                                <p className="mt-2 text-sm text-neutral-800">
                                    {t('Votre adresse e-mail n’est pas encore confirmée.')}{' '}
                                    <Link
                                        href={route('verification.send')}
                                        method="post"
                                        as="button"
                                        className="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        {t('Renvoyer l’e-mail de confirmation.')}
                                    </Link>
                                </p>

                                {status === 'verification-link-sent' && (
                                    <div className="mt-2 text-sm font-medium text-green-600">
                                        {t('Un nouveau lien de confirmation vient d’être envoyé à votre adresse e-mail.')}
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>{t('Enregistrer')}</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">{t('Enregistré')}</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <div className="space-y-6">
                    <HeadingSmall
                        title={t('Mes données')}
                        description={t('Téléchargez une copie de vos données personnelles, dans un fichier JSON lisible par une machine.')}
                    />
                    <Button asChild variant="outline">
                        <a href={route('profile.export')} download>
                            {t('Télécharger mes données')}
                        </a>
                    </Button>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
