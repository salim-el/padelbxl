import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LegalLayout, { type Club } from '@/layouts/legal-layout';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// Écrire au club (T03, F5). Le message part par e-mail au club ; l'application ne le garde pas.
export default function Contact({ club, sent }: { club: Club; sent: boolean }) {
    const t = useT();
    const { auth } = usePage<SharedData>().props;
    // Un joueur connecté n'a pas à retaper son nom et son adresse.
    const { data, setData, post, processing, errors, reset } = useForm({
        name: auth.user?.name ?? '',
        email: auth.user?.email ?? '',
        message: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('contact.store'), { preserveScroll: true, onSuccess: () => reset('message') });
    };

    return (
        <LegalLayout title={t('Écrire au club')}>
            {!club.email ? (
                <p className="mt-6">{t('Le formulaire n’est pas disponible pour le moment. Adressez-vous à l’accueil du club.')}</p>
            ) : (
                <>
                    <p className="mt-6">{t('Une question sur une réservation, une facture ou vos données ? Le club vous répond par e-mail.')}</p>
                    <p role="status" className="mt-4 font-medium text-[#2F6B3A]">
                        {sent ? t('Message envoyé. Le club vous répondra par e-mail.') : ''}
                    </p>
                    <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Nom')}</Label>
                            <Input
                                id="name"
                                required
                                maxLength={100}
                                autoComplete="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                aria-invalid={!!errors.name}
                                aria-describedby={errors.name ? 'name-error' : undefined}
                            />
                            <InputError id="name-error" message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('Adresse e-mail')}</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                maxLength={255}
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={!!errors.email}
                                aria-describedby={errors.email ? 'email-error' : undefined}
                            />
                            <InputError id="email-error" message={errors.email} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="message">{t('Message')}</Label>
                            <textarea
                                id="message"
                                required
                                minLength={10}
                                maxLength={2000}
                                rows={6}
                                value={data.message}
                                onChange={(e) => setData('message', e.target.value)}
                                aria-invalid={!!errors.message}
                                aria-describedby={errors.message ? 'message-error' : undefined}
                                className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm"
                            />
                            <InputError id="message-error" message={errors.message} />
                        </div>
                        <p className="text-sm text-[#5F6B78]">
                            {t('Votre nom, votre e-mail et votre message partent par e-mail au club. L’application ne les garde pas.')}
                        </p>
                        <Button type="submit" disabled={processing}>
                            {t('Envoyer')}
                        </Button>
                    </form>
                </>
            )}
        </LegalLayout>
    );
}
