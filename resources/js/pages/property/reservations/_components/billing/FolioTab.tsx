import { useCallback, useEffect, useState } from 'react';
import { Alert, Badge, Button, Card, EmptyState, LinkButton, Modal, RowMenu, Textarea } from '@/components/ui';
import { date, dateTime } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyUrl } from '@/lib/page';
import type { BillingProps } from '../BillingSlot';
import AddBillModal from './AddBillModal';
import AddChargeModal from './AddChargeModal';
import { billingApi, fmt, isPositive, submit, type FolioData, type FolioLineRow } from './shared';

function LineStatus({ l }: { l: FolioLineRow }) {
    if (l.reversal) return <Badge size="sm" status="reversal">{t('billing.line_status.reversal')}</Badge>;
    if (l.void) return <Badge size="sm" status="void">{t('billing.line_status.void')}</Badge>;
    if (l.invoice) return <Badge size="sm" status="invoiced">{l.invoice}</Badge>;
    return <Badge size="sm" status="posted">{t('billing.line_status.posted')}</Badge>;
}

/** The folio: posted charges with their taxes, room nights still to be posted, totals and voids. */
export default function FolioTab({ reservation, onChanged }: BillingProps) {
    const [data, setData] = useState<FolioData | null>(null);
    const [failed, setFailed] = useState<ApiError | null>(null);
    const [adding, setAdding] = useState(false);
    const [billing, setBilling] = useState(false);
    const [voiding, setVoiding] = useState<FolioLineRow | null>(null);
    const cur = reservation.currency;

    const load = useCallback(() => {
        setFailed(null);
        http.get<FolioData>(billingApi(reservation.id, '/folio')).then(setData).catch((e: ApiError) => setFailed(e));
    }, [reservation.id]);
    useEffect(load, [load, reservation.grand_total]);
    const changed = () => { load(); onChanged?.(); };

    if (failed) {
        if (failed.status === 403) return <EmptyState icon="lock" title={t('billing.no_access')} />;
        return <Alert tone="danger"><div className="row-between"><span>{failed.message}</span><Button size="sm" icon="refresh" onClick={load}>{t('billing.retry')}</Button></div></Alert>;
    }
    if (!data) return <Card title={t('billing.folio.title')}><div className="skeleton" style={{ height: 220 }} /></Card>;

    const s = data.summary;
    const title = <span className="row">{t('billing.folio.title')}{data.folio && <><span className="muted text-sm">{data.folio.no}</span><Badge size="sm" status={data.folio.status}>{t(`billing.folio_status.${data.folio.status}`)}</Badge></>}</span>;

    return (
        <Card title={title} actions={<div className="row">
            <LinkButton size="sm" variant="outline" icon="printer" href={propertyUrl(`/reservations/${reservation.id}/folio`)} target="_blank" rel="noopener">{t('billing.folio.print')}</LinkButton>
            {data.can.post && <Button size="sm" variant="outline" icon="receipt" onClick={() => setBilling(true)}>{t('billing.bill.title')}</Button>}
            {data.can.post && <Button size="sm" variant="primary" icon="plus" onClick={() => setAdding(true)}>{t('billing.folio.add_charge')}</Button>}
        </div>}>
            {data.lines.length === 0 && data.pending.length === 0
                ? <EmptyState icon="receipt" title={t('billing.folio.empty')} text={data.can.post ? t('billing.folio.empty_hint') : undefined} />
                : <div className="table-scroll"><table className="table folio-table">
                    <thead><tr>
                        <th>{t('billing.fields.date')}</th><th>{t('billing.fields.description')}</th><th className="num">{t('billing.fields.quantity')}</th>
                        <th className="num">{t('billing.fields.amount')}</th><th className="num">{t('billing.fields.tax')}</th><th className="num">{t('billing.fields.total')}</th>
                        <th>{t('billing.fields.status')}</th><th className="col-actions" />
                    </tr></thead>
                    <tbody>
                        {data.lines.map((l) => (
                            <tr key={l.id} className={l.void || l.reversal ? 'line-void' : undefined}>
                                <td title={l.posted_at ? `${dateTime(l.posted_at)}${l.posted_by ? ` · ${l.posted_by}` : ''}` : undefined}>{date(l.date)}</td>
                                <td>
                                    <span className="cell-main">{l.reversal ? `${t('billing.line_status.reversal')}: ` : ''}{l.description}</span>
                                    <span className="cell-sub">{l.department && l.department !== 'other' ? `${t(`billing.departments.${l.department}`)} · ` : ''}{t(`billing.types.${l.type}`)}{l.reference ? ` · #${l.reference}` : ''}{l.sac ? ` · SAC ${l.sac}` : ''}{l.void_reason ? ` · ${l.void_reason}` : ''}</span>
                                </td>
                                <td className="num">{Number(l.quantity)}</td>
                                <td className="num">{fmt(l.amount, cur)}</td>
                                <td className="num" title={l.taxes.map((x) => `${x.label ?? x.component}: ${fmt(x.amount, cur)}`).join('\n') || undefined}>{fmt(l.tax, cur)}</td>
                                <td className="num strong">{fmt(l.total, cur)}</td>
                                <td><LineStatus l={l} /></td>
                                <td className="col-actions">{l.can_void && <RowMenu items={[{ label: t('billing.folio.void'), icon: 'ban', danger: true, onClick: () => setVoiding(l) }]} />}</td>
                            </tr>
                        ))}
                        {data.pending.map((p, i) => (
                            <tr key={`p${i}`} className="line-pending">
                                <td>{date(p.date)}</td>
                                <td><span className="cell-main">{p.description}</span><span className="cell-sub">{t('billing.types.room')}</span></td>
                                <td className="num">1</td>
                                <td className="num">{fmt(p.amount, cur)}</td>
                                <td className="num">{fmt(p.tax, cur)}</td>
                                <td className="num">{fmt(p.total, cur)}</td>
                                <td><Badge size="sm" status="pending">{t('billing.line_status.pending')}</Badge></td>
                                <td />
                            </tr>
                        ))}
                    </tbody>
                </table></div>}
            <div className="folio-foot">
                <div className="folio-taxes">
                    {data.taxes.length > 0 && <>
                        <h4>{t('billing.folio.tax_breakdown')}</h4>
                        {data.taxes.map((x) => <div key={x.label} className="ps-row"><span>{x.label}</span><span className="num">{fmt(x.amount, cur)}</span></div>)}
                    </>}
                    {isPositive(s.pending_room_charges) && <p className="muted text-sm">{t('billing.folio.pending_hint')}</p>}
                </div>
                <div className="price-summary">
                    <div className="ps-row"><span>{t('billing.summary.posted')}</span><span className="num">{fmt(s.posted, cur)}</span></div>
                    <div className="ps-row"><span>{t('billing.summary.pending')}</span><span className="num">{fmt(s.pending_room_charges, cur)}</span></div>
                    <div className="ps-row strong"><span>{t('billing.summary.total')}</span><span className="num">{fmt(s.total, cur)}</span></div>
                    <div className="ps-row"><span>{t('billing.summary.paid')}</span><span className="num">{fmt(s.paid, cur)}</span></div>
                    <div className="ps-row total"><span>{t('billing.summary.balance')}</span><span className="num">{fmt(s.balance, cur)}</span></div>
                </div>
            </div>
            {billing && <AddBillModal reservation={reservation} open onClose={() => setBilling(false)} onSaved={() => { setBilling(false); changed(); }} />}
            {adding && <AddChargeModal reservation={reservation} open onClose={() => setAdding(false)} onSaved={() => { setAdding(false); changed(); }} />}
            {voiding && <VoidModal line={voiding} reservationId={reservation.id} currency={cur} onClose={() => setVoiding(null)} onDone={() => { setVoiding(null); changed(); }} />}
        </Card>
    );
}

function VoidModal({ line, reservationId, currency, onClose, onDone }: { line: FolioLineRow; reservationId: string; currency: string; onClose: () => void; onDone: () => void }) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await submit(() => http.post<{ message: string }>(billingApi(reservationId, `/folio/lines/${line.id}/void`), { reason }), setError);
        setBusy(false);
        if (res) onDone();
    };
    return (
        <Modal open title={t('billing.folio.void_title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="danger" icon="ban" loading={busy} onClick={save}>{t('billing.folio.void')}</Button>
        </>}>
            <form className="stack" onSubmit={(e) => { e.preventDefault(); save(); }}>
                <p>{t('billing.folio.void_confirm', { item: line.description, amount: fmt(line.total, currency) })}</p>
                {line.invoice && <Alert tone="warn">{t('billing.folio.void_credit_note', { invoice: line.invoice })}</Alert>}
                <Textarea label={t('billing.fields.reason')} required rows={2} maxLength={255} autoFocus value={reason} onChange={(e) => setReason(e.target.value)} error={error?.field('reason') ?? error?.field('line')} />
            </form>
        </Modal>
    );
}
