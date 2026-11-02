import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

// Components...
import InputError from '@/components/input-error';
import { PasswordInput } from '@/components/password-input';
import { Button } from '@/components/ui/button';

import { Label } from '@/components/ui/label';

import HeadingSmall from '@/components/heading-small';

import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

export default function DeleteUser() {
    const t = useT();
    const passwordInput = useRef<HTMLInputElement>(null);
    const { data, setData, delete: destroy, processing, reset, errors, clearErrors } = useForm({ password: '' });

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        clearErrors();
        reset();
    };

    return (
        <div className="space-y-6">
            <HeadingSmall title={t('Supprimer le compte')} description={t('Supprimer votre compte et les données qui y sont liées')} />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">{t('Attention')}</p>
                    <p className="text-sm">{t('Cette action est définitive.')}</p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="destructive">{t('Supprimer le compte')}</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>{t('Voulez-vous vraiment supprimer votre compte ?')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Vos données personnelles seront effacées. Les factures déjà émises restent conservées 7 ans, comme la loi l’impose. Entrez votre mot de passe pour confirmer.',
                            )}
                        </DialogDescription>
                        <form className="space-y-6" onSubmit={deleteUser}>
                            <div className="grid gap-2">
                                <Label htmlFor="password" className="sr-only">
                                    {t('Mot de passe')}
                                </Label>

                                <PasswordInput
                                    id="password"
                                    name="password"
                                    ref={passwordInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder={t('Mot de passe')}
                                    autoComplete="current-password"
                                />

                                <InputError message={errors.password} />
                            </div>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button variant="secondary" onClick={closeModal}>
                                        {t('Annuler')}
                                    </Button>
                                </DialogClose>

                                <Button variant="destructive" disabled={processing} asChild>
                                    <button type="submit">{t('Supprimer le compte')}</button>
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
