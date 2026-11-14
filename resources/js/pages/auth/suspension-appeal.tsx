import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// F29 : le joueur suspendu répond au motif depuis le lien reçu par e-mail, sans se connecter.
export default function SuspensionAppeal({
    action,
    reason,
    until,
    already,
}: {
    action: string;
    reason: string;
    until: string | null;
    already: boolean;
}) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm({ message: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(action);
    };

    return (
        <AuthLayout
            title={t('Contester la suspension')}
            description={t('Une autre personne que celle qui a suspendu votre compte examinera votre réponse.')}
        >
            <Head title={t('Contester la suspension')}>
                <meta name="robots" content="noindex" />
            </Head>
            <div className="rounded-lg border border-[#DAD7CC] bg-white p-4 text-sm">
                <p className="font-semibold">{t('Motif de la suspension')}</p>
                <p className="mt-1">{reason}</p>
                <p className="mt-2 text-[#5F6B78]">
                    {until ? t('Fin prévue le :date.', { date: until }) : t('Jusqu’à une nouvelle décision du club.')}
                </p>
            </div>
            {already ? (
                <p role="status" className="text-sm">
                    {t('Votre contestation est déjà reçue. Le club vous répond par e-mail.')}
                </p>
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <label className="flex flex-col gap-2 text-sm font-medium">
                        {t('Votre réponse')}
                        <textarea
                            required
                            minLength={20}
                            maxLength={2000}
                            rows={6}
                            value={data.message}
                            onChange={(e) => setData('message', e.target.value)}
                            className="rounded-lg border border-[#DAD7CC] bg-white p-3 font-normal"
                        />
                    </label>
                    <InputError message={errors.message} />
                    <Button type="submit" disabled={processing}>
                        {t('Envoyer ma contestation')}
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
