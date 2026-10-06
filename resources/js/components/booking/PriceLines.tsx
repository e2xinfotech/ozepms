import { money } from '@/lib/format';
import { t } from '@/lib/i18n';

export interface PriceRate {
    price_before: string; discount: string; grand_total: string; offers: { name: string }[];
    tax_lines: { name: string; rate: string | null; amount: string }[];
}

/** Room charges, offers, every tax / fee by name and the total — the same lines on results and checkout. */
export function PriceLines({ rate, cur }: { rate: PriceRate; cur: string }) {
    return (
        <div className="price-summary">
            <div className="ps-row"><span>{t('booking.checkout.room_charges')}</span><span className="num">{money(rate.price_before, cur)}</span></div>
            {Number(rate.discount) > 0 && <div className="ps-row"><span>{t('booking.checkout.discount')}{rate.offers.length ? ` · ${rate.offers.map((o) => o.name).join(', ')}` : ''}</span><span className="num">− {money(rate.discount, cur)}</span></div>}
            {rate.tax_lines.map((tx) => <div key={tx.name} className="ps-row sub"><span>{tx.name}{tx.rate ? ` (${tx.rate}%)` : ''}</span><span className="num">{money(tx.amount, cur)}</span></div>)}
            <div className="ps-row total"><span>{t('booking.checkout.total')}</span><span className="num strong">{money(rate.grand_total, cur)}</span></div>
        </div>
    );
}

