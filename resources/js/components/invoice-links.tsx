import { useT } from '@/lib/i18n';

// Liens de téléchargement directs (pas de visite Inertia) : le serveur renvoie un fichier PDF.
// Une facture porte une note de crédit par remboursement : chacune a son lien.
export default function InvoiceLinks({ booking, invoice, creditNotes }: { booking: string; invoice: string; creditNotes: string[] }) {
    const t = useT();
    const base = `/bookings/${booking}/invoices/${invoice}`;

    return (
        <>
            <a href={`${base}/pdf`} download className="text-[#1D5C7A] underline">
                {t('Télécharger le PDF')}
            </a>
            {creditNotes.map((number) => (
                <a key={number} href={`${base}/credit-notes/${number}/pdf`} download className="text-[#1D5C7A] underline">
                    {t('Note de crédit :number (PDF)', { number })}
                </a>
            ))}
        </>
    );
}
