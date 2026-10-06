import { Badge, Button, PageHeader } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date, money } from '@/lib/format';
import { t } from '@/lib/i18n';
import { propertyUrl } from '@/lib/page';
import type { FolioData, PaymentRow } from '../reservations/_components/billing/shared';

interface Props {
    reservation: { id: string; ref: string; guest_name: string; status: string; check_in: string; check_out: string; nights: number; currency: string };
    property: { name: string; legal_name: string | null; tax_no: string | null; address: string[]; phone: string | null; email: string | null };
    data: { folio: FolioData; payments: PaymentRow[] };
}

/** Printable guest folio (all charges, payments and the balance). Not a tax invoice. */
function FolioPrint({ reservation: r, property: p, data }: Props) {
    const m = (v: string | number) => money(v, r.currency);
    const f = data.folio;
    return (
        <div className="content">
            <div className="no-print">
                <PageHeader back={propertyUrl(`/reservations/${r.id}?tab=payments`)} title={`${t('billing.folio.title')} ${f.folio?.no ?? ''}`}
                    description={`${r.ref} · ${r.guest_name}`} actions={<Button variant="primary" icon="printer" onClick={() => window.print()}>{t('billing.invoice.print')}</Button>} />
            </div>
            <article className="invoice-doc">
                <header className="inv-head">
                    <div>
                        <h2>{p.legal_name || p.name}</h2>
                        {p.address.map((a, i) => <div key={i}>{a}</div>)}
                        {(p.phone || p.email) && <div>{[p.phone, p.email].filter(Boolean).join(' · ')}</div>}
                        {p.tax_no && <div><strong>GSTIN:</strong> {p.tax_no}</div>}
                    </div>
                    <div className="inv-title">
                        <h1>{t('billing.folio.guest_folio')}</h1>
                        <dl>
                            <dt>{t('billing.invoice.folio')}</dt><dd>{f.folio?.no ?? '—'}</dd>
                            <dt>{t('billing.invoice.booking')}</dt><dd>{r.ref}</dd>
                            <dt>{t('billing.invoice.stay')}</dt><dd>{date(r.check_in)} – {date(r.check_out)}</dd>
                        </dl>
                    </div>
                </header>
                <section className="inv-parties"><div><h4>{t('billing.invoice.guest')}</h4><strong>{r.guest_name}</strong></div></section>
                <table className="inv-table">
                    <thead><tr><th>{t('billing.fields.date')}</th><th>{t('billing.fields.description')}</th><th className="num">{t('billing.fields.amount')}</th><th className="num">{t('billing.fields.tax')}</th><th className="num">{t('billing.fields.total')}</th></tr></thead>
                    <tbody>
                        {f.lines.filter((l) => !l.void && !l.reversal).map((l) => (
                            <tr key={l.id}><td>{date(l.date)}</td><td>{l.description}</td><td className="num">{m(l.amount)}</td><td className="num">{m(l.tax)}</td><td className="num">{m(l.total)}</td></tr>
                        ))}
                        {f.pending.map((l, i) => (
                            <tr key={`p${i}`}><td>{date(l.date)}</td><td>{l.description} <Badge size="sm" status="pending">{t('billing.line_status.pending')}</Badge></td><td className="num">{m(l.amount)}</td><td className="num">{m(l.tax)}</td><td className="num">{m(l.total)}</td></tr>
                        ))}
                    </tbody>
                </table>
                {data.payments.length > 0 && <table className="inv-table small">
                    <thead><tr><th>{t('billing.fields.date')}</th><th>{t('billing.payment.payment')}</th><th>{t('billing.fields.reference')}</th><th className="num">{t('billing.fields.amount')}</th></tr></thead>
                    <tbody>{data.payments.filter((x) => ['captured', 'refunded', 'partially_refunded'].includes(x.status)).map((x) => (
                        <tr key={x.id}><td>{date(x.date)}</td><td>{x.kind === 'refund' ? t('billing.payment.refund') : t(`billing.methods.${x.method === 'gateway' ? 'online' : x.method}`)}</td><td>{x.reference ?? '—'}</td><td className="num">{m(x.kind === 'refund' ? `-${x.amount}` : x.amount)}</td></tr>
                    ))}</tbody>
                </table>}
                <section className="inv-bottom">
                    <div><p className="muted text-sm">{t('billing.folio.not_invoice')}</p></div>
                    <div className="price-summary">
                        <div className="ps-row strong"><span>{t('billing.summary.total')}</span><span className="num">{m(f.summary.total)}</span></div>
                        <div className="ps-row"><span>{t('billing.summary.paid')}</span><span className="num">{m(f.summary.paid)}</span></div>
                        <div className="ps-row total"><span>{t('billing.summary.balance')}</span><span className="num">{m(f.summary.balance)}</span></div>
                    </div>
                </section>
            </article>
        </div>
    );
}

createPage(FolioPrint);
