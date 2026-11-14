import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import TermsLink from '@/components/terms-link';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

interface RegisterForm {
    account_type: 'individual' | 'company';
    company_name: string;
    vat_number: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    birth_date: string;
    level: string;
    guardian_email: string;
    accept_terms: boolean;
}

export default function Register() {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        account_type: 'individual',
        company_name: '',
        vat_number: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        birth_date: '',
        level: '',
        guardian_email: '',
        accept_terms: false,
    });

    // F1 : sous 13 ans, le compte est créé par un parent, qui donne son adresse.
    const thirteenYearsAgo = new Date();
    thirteenYearsAgo.setFullYear(thirteenYearsAgo.getFullYear() - 13);
    const under13 = data.birth_date !== '' && new Date(data.birth_date) > thirteenYearsAgo;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Créer un compte" description="Entrez vos informations pour créer votre compte">
            <Head title={t('Inscription')} />
            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">{t('Vous êtes')}</legend>
                        <div className="grid grid-cols-2 gap-2">
                            {(
                                [
                                    ['individual', 'Un particulier'],
                                    ['company', 'Une entreprise'],
                                ] as const
                            ).map(([value, label]) => (
                                <label
                                    key={value}
                                    className={`cursor-pointer rounded-lg border-2 p-3 text-center text-sm font-semibold has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#1D5C7A] ${
                                        data.account_type === value ? 'border-[#1D5C7A] bg-[#E6ECF2] text-[#17304A]' : 'border-[#DAD7CC] bg-white'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="account_type"
                                        value={value}
                                        checked={data.account_type === value}
                                        onChange={() => setData('account_type', value)}
                                        className="sr-only"
                                    />
                                    {t(label)}
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    {data.account_type === 'company' && (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="company_name">{t('Dénomination de l’entreprise')}</Label>
                                <Input
                                    id="company_name"
                                    required
                                    autoComplete="organization"
                                    value={data.company_name}
                                    onChange={(e) => setData('company_name', e.target.value)}
                                    disabled={processing}
                                    placeholder="Atelier Nova SRL"
                                />
                                <InputError message={errors.company_name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="vat_number">{t('Numéro de TVA')}</Label>
                                <Input
                                    id="vat_number"
                                    required
                                    value={data.vat_number}
                                    onChange={(e) => setData('vat_number', e.target.value)}
                                    disabled={processing}
                                    placeholder="BE0123456789"
                                    aria-describedby="vat_help"
                                />
                                <p id="vat_help" className="text-xs text-[#5F6B78]">
                                    {t(
                                        'Il figure sur vos factures. Les factures électroniques entre entreprises belges passent par la comptabilité du club.',
                                    )}
                                </p>
                                <InputError message={errors.vat_number} />
                            </div>
                        </>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="name">{data.account_type === 'company' ? t('Nom de la personne de contact') : t('Nom complet')}</Label>
                        <Input
                            id="name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            disabled={processing}
                            placeholder={t('Prénom et nom')}
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('Adresse e-mail')}</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={2}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder={t('prenom.nom@exemple.be')}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="birth_date">{t('Date de naissance')}</Label>
                            <Input
                                id="birth_date"
                                type="date"
                                required
                                autoComplete="bday"
                                value={data.birth_date}
                                onChange={(e) => setData('birth_date', e.target.value)}
                                disabled={processing}
                            />
                            <InputError message={errors.birth_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="level">{t('Niveau estimé')}</Label>
                            <select
                                id="level"
                                required
                                value={data.level}
                                onChange={(e) => setData('level', e.target.value)}
                                disabled={processing}
                                aria-describedby="level_help"
                                className="h-9 rounded-md border border-[#DAD7CC] bg-white px-3 text-sm"
                            >
                                <option value="">{t('Choisir')}</option>
                                {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.level} />
                        </div>
                    </div>
                    <p id="level_help" className="-mt-4 text-xs text-[#5F6B78]">
                        {t('De 1 (débutant) à 7 (compétition). Il sert aux appels à joueurs.')}
                    </p>

                    {under13 && (
                        <div className="grid gap-2">
                            <Label htmlFor="guardian_email">{t('E-mail du parent')}</Label>
                            <Input
                                id="guardian_email"
                                type="email"
                                required
                                value={data.guardian_email}
                                onChange={(e) => setData('guardian_email', e.target.value)}
                                disabled={processing}
                                aria-describedby="guardian_help"
                            />
                            <p id="guardian_help" className="text-xs text-[#5F6B78]">
                                {t('Moins de 13 ans : le compte est créé par un parent, qui accepte les conditions.')}
                            </p>
                            <InputError message={errors.guardian_email} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="password">{t('Mot de passe')}</Label>
                        <PasswordInput
                            id="password"
                            required
                            tabIndex={3}
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                            placeholder={t('Mot de passe')}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">{t('Confirmer le mot de passe')}</Label>
                        <PasswordInput
                            id="password_confirmation"
                            required
                            tabIndex={4}
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                            placeholder={t('Confirmer le mot de passe')}
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <div className="grid gap-1">
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4 accent-[#1D5C7A]"
                                checked={data.accept_terms}
                                onChange={(e) => setData('accept_terms', e.target.checked)}
                            />
                            {t('J’accepte les conditions générales et la politique de vie privée.')}
                        </label>
                        <TermsLink />
                        <InputError message={errors.accept_terms} />
                    </div>

                    <Button type="submit" className="mt-2 w-full" tabIndex={5} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {t('Créer mon compte')}
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    {t('Déjà un compte ?')}{' '}
                    <TextLink href={route('login')} tabIndex={6}>
                        {t('Se connecter')}
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
