// Components
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

export default function VerifyEmail({ status }: { status?: string }) {
    const t = useT();
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <AuthLayout title={t('Confirmez votre adresse e-mail')} description={t('Cliquez sur le lien que nous venons de vous envoyer par e-mail.')}>
            <Head title={t('Confirmation de l’adresse e-mail')} />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {t('Un nouveau lien de confirmation vient d’être envoyé à l’adresse donnée lors de l’inscription.')}
                </div>
            )}

            <form onSubmit={submit} className="space-y-6 text-center">
                <Button disabled={processing} variant="secondary">
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {t('Renvoyer l’e-mail de confirmation')}
                </Button>

                <TextLink href={route('logout')} method="post" className="mx-auto block text-sm">
                    {t('Se déconnecter')}
                </TextLink>
            </form>
        </AuthLayout>
    );
}
