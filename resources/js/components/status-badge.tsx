import { useT } from '@/lib/i18n';
import { STATUS_LABELS } from '@/lib/money';

// Teintes d'état de la charte T10 : fond clair, texte foncé de la même famille.
const STYLES: Record<string, string> = {
    pending_payment: 'bg-[#E6ECF2] text-[#17304A]',
    confirmed: 'bg-[#EEF4D6] text-[#4E6600]',
    used: 'bg-[#E3F0F7] text-[#1D5C7A]',
    cancelled_refunded: 'bg-[#ECEAE3] text-[#6B7480]',
    cancelled_unrefunded: 'bg-[#FCE6E8] text-[#9E2833]',
    expired: 'bg-[#ECEAE3] text-[#6B7480]',
    missed: 'bg-[#FCE6E8] text-[#9E2833]',
    frozen: 'bg-[#E6ECF2] text-[#17304A]',
};

export function StatusBadge({ status }: { status: string }) {
    const t = useT();
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${STYLES[status] ?? 'bg-muted text-muted-foreground'}`}
        >
            {t(STATUS_LABELS[status] ?? status)}
        </span>
    );
}
