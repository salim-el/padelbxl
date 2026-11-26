import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TermsLink from '@/components/terms-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

interface Document {
    type: 'terms' | 'privacy';
    version: string;
    content: string;
    published_at: string;
}

// F7 : une nouvelle version des conditions ou de la politique de vie privée est acceptée avant de réserver.
export default function AcceptLegal({ documents }: { documents: Document[] }) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm<{ accept: boolean }>({ accept: false });
    const names = { terms: t('Conditions générales'), privacy: t('Politique de vie privée') };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('legal.accept.store'));
    };

    return (
        <AuthLayout title={t('Nos conditions ont changé')} description={t('Acceptez la nouvelle version pour continuer à réserver.')}>
            <Head title={t('Nos conditions ont changé')}>
                <meta name="robots" content="noindex" />
            </Head>
            <form className="flex flex-col gap-6" onSubmit={submit}>
                <ul className="grid gap-3">
                    {documents.map((d) => (
                        <li key={d.type} className="rounded-lg border border-[#DAD7CC] bg-white p-4 text-sm">
                            <p className="font-semibold">
                                {names[d.type]} · {t('version :version du :date', { version: d.version, date: d.published_at })}
                            </p>
                            <p className="mt-1 text-[#5F6B78]">{d.content}</p>
                        </li>
                    ))}
                </ul>
                <TermsLink />
                <label className="flex items-start gap-3 text-sm">
                    <input
                        type="checkbox"
                        className="mt-0.5 size-4 accent-[#1D5C7A]"
                        checked={data.accept}
                        onChange={(e) => setData('accept', e.target.checked)}
                    />
                    {t('J’accepte la nouvelle version.')}
                </label>
                <InputError message={errors.accept} />
                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {t('Continuer')}
                </Button>
            </form>
        </AuthLayout>
    );
}
