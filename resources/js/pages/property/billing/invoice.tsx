import { useState } from 'react';
import { Alert, Badge, Button, LinkButton, PageHeader } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { date, money } from '@/lib/format';
import { t } from '@/lib/i18n';
import { propertyUrl } from '@/lib/page';
import { CancelModal } from '../reservations/_components/billing/InvoiceList';

interface Party { name: string; legal_name?: string; address?: string[] | string | null; tax_no: string | null; state: string | null; state_code: string | null; phone?: string | null; email?: string | null; country?: string | null }
interface Line { date: string; type: string; description: string; sac: string | null; quantity: string; unit_price: string; taxable: string; taxes: { component: string; rate: string; fixed?: boolean; amount: string }[]; tax_total: string; total: string }
interface Snapshot {
    type: 'tax_invoice' | 'credit_note'; number: string; date: string; financial_year: string; currency: string;
    parties: { supplier: Party; bill_to: Party & { guest_name: string }; place_of_supply: { code: string | null; name: string | null } };
    reservation: { ref: string; folio_no: string; guest_name: string; check_in: string; check_out: string; nights: number; rooms: string[]; adults: number; children: number };
    lines: Line[]; tax_summary: { component: string; name: string; rate: string; fixed?: boolean; taxable: string; amount: string }[];
    totals: { taxable: string; cgst: string; sgst: string; igst: string; other: string; tax: string; round_off: string; grand: string };
    amount_in_words: string; payments: { kind: string; method: string; amount: string; date: string | null; reference: string | null }[];
    original?: { number: string; date: string; id: string }; reason?: string;
}
interface Props {
    invoice: { id: string; type: string; number: string; cancelled_at: string | null; original: { id: string; number: string } | null; credit_notes: { id: string; number: string; total: string; date: string }[]; snapshot: Snapshot; logo: string | null };
    reservation: { id: string; ref: string } | null;
    can: { cancel: boolean };
}

/** Printable GST tax invoice / credit note (frozen snapshot; print or save as PDF from the browser). */
function InvoicePage({ invoice, reservation, can }: Props) {
    const s = invoice.snapshot;
    const cur = s.currency;
    const m = (v: string) => money(v, cur);
    const [cancelling, setCancelling] = useState(false);
    const credit = s.type === 'credit_note';
    const components = Array.from(new Set(s.lines.flatMap((l) => l.taxes.map((x) => x.component))));
    const comp = (l: Line, c: string) => l.taxes.filter((x) => x.component === c).reduce((a, x) => a + Number(x.amount), 0);
    const rate = (l: Line, c: string) => l.taxes.find((x) => x.component === c)?.rate;
    const fixedTax = (l: Line, c: string) => !!l.taxes.find((x) => x.component === c)?.fixed;
    // SAC/HSN codes belong to Indian GST invoices only.
    const showSac = s.parties.supplier.country === 'IN';
    const supplierAddress = Array.isArray(s.parties.supplier.address) ? s.parties.supplier.address : [];
    const paid = s.payments.reduce((a, p) => a + (p.kind === 'refund' ? -Number(p.amount) : Number(p.amount)), 0);

    return (
        <div className="content">
            <div className="no-print">
                <PageHeader back={reservation ? propertyUrl(`/reservations/${reservation.id}?tab=payments`) : undefined}
                    title={<span className="row">{credit ? t('billing.invoice.types.credit_note') : t('billing.invoice.types.tax_invoice')} {invoice.number}
                        {invoice.cancelled_at && <Badge status="cancelled">{t('billing.invoice.cancelled')}</Badge>}</span>}
                    description={reservation ? t('billing.invoice.for_reservation', { ref: reservation.ref }) : undefined}
                    actions={<>
                        {can.cancel && !credit && !invoice.cancelled_at && <Button variant="danger-soft" icon="circle-x" onClick={() => setCancelling(true)}>{t('billing.invoice.cancel')}</Button>}
                        <Button variant="primary" icon="printer" onClick={() => window.print()}>{t('billing.invoice.print')}</Button>
                    </>} />
                {invoice.cancelled_at && <Alert tone="warn">{t('billing.invoice.cancelled_note', { notes: invoice.credit_notes.map((n) => n.number).join(', ') })}</Alert>}
                {!invoice.cancelled_at && invoice.credit_notes.length > 0 && <Alert tone="info">{t('billing.invoice.has_credit_notes', { notes: invoice.credit_notes.map((n) => n.number).join(', ') })}</Alert>}
            </div>

            <article className="invoice-doc">
                <header className="inv-head">
                    <div>
                        {invoice.logo && <img src={invoice.logo} alt="" className="inv-logo" />}
                        <h2>{s.parties.supplier.legal_name || s.parties.supplier.name}</h2>
                        {s.parties.supplier.legal_name && s.parties.supplier.legal_name !== s.parties.supplier.name && <div>{s.parties.supplier.name}</div>}
                        {supplierAddress.map((a, i) => <div key={i}>{a}</div>)}
                        {(s.parties.supplier.phone || s.parties.supplier.email) && <div>{[s.parties.supplier.phone, s.parties.supplier.email].filter(Boolean).join(' · ')}</div>}
                        {s.parties.supplier.tax_no && <div><strong>GSTIN:</strong> {s.parties.supplier.tax_no}</div>}
                    </div>
                    <div className="inv-title">
                        <h1>{credit ? t('billing.invoice.types.credit_note') : t('billing.invoice.types.tax_invoice')}</h1>
                        <dl>
                            <dt>{t('billing.invoice.number')}</dt><dd>{s.number}</dd>
                            <dt>{t('billing.fields.date')}</dt><dd>{date(s.date)}</dd>
                            {s.original && <><dt>{t('billing.invoice.original')}</dt><dd>{s.original.number} · {date(s.original.date)}</dd></>}
                            <dt>{t('billing.invoice.place_of_supply')}</dt><dd>{[s.parties.place_of_supply.code, s.parties.place_of_supply.name].filter(Boolean).join(' - ') || '—'}</dd>
                        </dl>
                    </div>
                </header>

                <section className="inv-parties">
                    <div>
                        <h4>{t('billing.invoice.bill_to')}</h4>
                        <strong>{s.parties.bill_to.name}</strong>
                        {s.parties.bill_to.address && <div>{String(s.parties.bill_to.address)}</div>}
                        {s.parties.bill_to.state && <div>{s.parties.bill_to.state}{s.parties.bill_to.state_code ? ` (${s.parties.bill_to.state_code})` : ''}</div>}
                        {s.parties.bill_to.tax_no && <div><strong>GSTIN:</strong> {s.parties.bill_to.tax_no}</div>}
                    </div>
                    <div>
                        <h4>{t('billing.invoice.stay')}</h4>
                        <div>{t('billing.invoice.booking')}: {s.reservation.ref} · {t('billing.invoice.folio')}: {s.reservation.folio_no}</div>
                        <div>{s.reservation.guest_name}</div>
                        <div>{date(s.reservation.check_in)} – {date(s.reservation.check_out)} · {t('billing.invoice.nights', { count: s.reservation.nights })}</div>
                        {s.reservation.rooms.length > 0 && <div>{s.reservation.rooms.join(', ')}</div>}
                    </div>
                </section>

                <table className="inv-table">
                    <thead><tr>
                        <th>#</th><th>{t('billing.fields.description')}</th>{showSac && <th>SAC/HSN</th>}<th className="num">{t('billing.fields.quantity')}</th>
                        <th className="num">{t('billing.fields.unit_price')}</th><th className="num">{t('billing.invoice.taxable')}</th>
                        {components.map((c) => <th key={c} className="num">{c}</th>)}
                        <th className="num">{t('billing.fields.total')}</th>
                    </tr></thead>
                    <tbody>{s.lines.map((l, i) => (
                        <tr key={i}>
                            <td>{i + 1}</td>
                            <td>{l.description}<span className="cell-sub">{date(l.date)}</span></td>
                            {showSac && <td>{l.sac ?? '—'}</td>}
                            <td className="num">{Number(l.quantity)}</td>
                            <td className="num">{m(l.unit_price)}</td>
                            <td className="num">{m(l.taxable)}</td>
                            {components.map((c) => <td key={c} className="num">{rate(l, c) ? <>{m(String(comp(l, c)))}{!fixedTax(l, c) && <span className="cell-sub">{Number(rate(l, c))}%</span>}</> : '—'}</td>)}
                            <td className="num">{m(l.total)}</td>
                        </tr>
                    ))}</tbody>
                </table>

                <section className="inv-bottom">
                    <div>
                        {s.tax_summary.length > 0 && <table className="inv-table small">
                            <thead><tr><th>{t('billing.invoice.tax')}</th><th className="num">{t('billing.invoice.rate')}</th><th className="num">{t('billing.invoice.taxable')}</th><th className="num">{t('billing.fields.amount')}</th></tr></thead>
                            <tbody>{s.tax_summary.map((x, i) => <tr key={i}><td>{x.name}</td><td className="num">{x.fixed ? t('billing.invoice.fixed') : `${Number(x.rate)}%`}</td><td className="num">{m(x.taxable)}</td><td className="num">{m(x.amount)}</td></tr>)}</tbody>
                        </table>}
                        <p className="inv-words"><strong>{t('billing.invoice.in_words')}:</strong> {s.amount_in_words}</p>
                        {s.reason && <p><strong>{t('billing.fields.reason')}:</strong> {s.reason}</p>}
                    </div>
                    <div className="price-summary">
                        <div className="ps-row"><span>{t('billing.invoice.taxable')}</span><span className="num">{m(s.totals.taxable)}</span></div>
                        {Number(s.totals.cgst) !== 0 && <div className="ps-row"><span>CGST</span><span className="num">{m(s.totals.cgst)}</span></div>}
                        {Number(s.totals.sgst) !== 0 && <div className="ps-row"><span>SGST</span><span className="num">{m(s.totals.sgst)}</span></div>}
                        {Number(s.totals.igst) !== 0 && <div className="ps-row"><span>IGST</span><span className="num">{m(s.totals.igst)}</span></div>}
                        {Number(s.totals.other) !== 0 && <div className="ps-row"><span>{t('billing.invoice.other_taxes')}</span><span className="num">{m(s.totals.other)}</span></div>}
                        {Number(s.totals.round_off) !== 0 && <div className="ps-row"><span>{t('billing.invoice.round_off')}</span><span className="num">{m(s.totals.round_off)}</span></div>}
                        <div className="ps-row total"><span>{credit ? t('billing.invoice.credit_total') : t('billing.invoice.grand_total')}</span><span className="num">{m(s.totals.grand)}</span></div>
                        {!credit && s.payments.length > 0 && <div className="ps-row"><span>{t('billing.invoice.paid_to_date')}</span><span className="num">{money(paid, cur)}</span></div>}
                    </div>
                </section>
                <footer className="inv-foot">
                    <span>{t('billing.invoice.computer_generated')}</span>
                    <span className="inv-sign">{t('billing.invoice.signatory')}</span>
                </footer>
            </article>
            {cancelling && <CancelModal invoice={{ id: invoice.id, number: invoice.number }} onClose={() => setCancelling(false)} onDone={() => window.location.reload()} />}
            {reservation && <div className="no-print"><LinkButton variant="ghost" icon="arrow-left" href={propertyUrl(`/reservations/${reservation.id}?tab=payments`)}>{t('billing.invoice.back')}</LinkButton></div>}
        </div>
    );
}

createPage(InvoicePage);
