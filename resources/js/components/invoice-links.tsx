import { useT } from '@/lib/i18n';

// Liens de téléchargement directs (pas de visite Inertia) : le serveur renvoie un fichier PDF.
export default function InvoiceLinks({ booking, invoice, creditNote }: { booking: string; invoice: string; creditNote: string | null }) {
    const t = useT();
    const base = `/bookings/${booking}/invoices/${invoice}`;

    return (
        <>
            <a href={`${base}/pdf`} download className="text-[#1D5C7A] underline">
                {t('Télécharger le PDF')}
            </a>
            {creditNote && (
                <a href={`${base}/credit-note/pdf`} download className="text-[#1D5C7A] underline">
                    {t('Note de crédit :number (PDF)', { number: creditNote })}
                </a>
            )}
        </>
    );
}
