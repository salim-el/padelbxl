import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

// Seul le personnel passe par cet écran : il reste en français comme les autres écrans du club.
export default function TwoFactorChallenge() {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset('code') });
    };

    return (
        <AuthLayout title="Code de vérification" description="Entrez le code à six chiffres affiché par votre application d’authentification.">
            <Head title="Code de vérification" />

            <form onSubmit={submit} className="space-y-6">
                <div className="grid gap-2">
                    <Label htmlFor="code">Code</Label>
                    <Input
                        id="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        autoFocus
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        className="font-mono tracking-widest"
                    />
                    <InputError message={errors.code} />
                </div>
                <Button className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Valider
                </Button>
            </form>
        </AuthLayout>
    );
}
