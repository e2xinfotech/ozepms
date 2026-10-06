import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge, Button, Dropdown, EmptyState, Flag, Icon, Input, KeyValue, LinkButton, Select, SidePanel, Tabs, Textarea, toast } from '@/components/ui';
import { date, dateTime, money } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import type { GuestDetail } from './types';

const DOC_TYPES = ['id_front', 'id_back', 'passport', 'visa', 'registration_card', 'other'];

/** Guest profile panel (design guests.png): contact, ID, tags, stay history, notes, documents, preferences. */
export function GuestPanel({ id, can, onClose, onEdit }: {
    id: string; can: { update: boolean; reserve: boolean; reservations: boolean; folio: boolean }; onClose: () => void; onEdit: (g: GuestDetail) => void;
}) {
    const [g, setG] = useState<GuestDetail | null>(null);
    const [failed, setFailed] = useState<ApiError | null>(null);
    const [tab, setTab] = useState('stays');
    const [tagInput, setTagInput] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        let alive = true;
        setFailed(null);
        http.get<{ guest: GuestDetail }>(propertyApiUrl(`/guests/${id}`)).then((r) => alive && setG(r.guest)).catch((e: ApiError) => alive && setFailed(e));
        return () => { alive = false; };
    }, [id]);
    useEffect(() => load(), [load]);

    if (failed) return <SidePanel title={t('guests.title')} onClose={onClose}><EmptyState icon="circle-alert" title={failed.message} action={<Button icon="refresh" onClick={load}>{t('reservations.retry')}</Button>} /></SidePanel>;
    if (!g || g.id !== id) return <SidePanel title={t('guests.title')} onClose={onClose}><div className="sp-section stack" style={{ borderTop: 0 }}><div className="skeleton" style={{ height: 70 }} /><div className="skeleton" style={{ height: 240 }} /></div></SidePanel>;

    const saveTags = async (tags: string[]) => {
        setBusy(true);
        try {
            const res = await http.put<{ message: string; tags: string[] }>(propertyApiUrl(`/guests/${g.id}/tags`), { tags });
            toast.success(res.message);
            setG({ ...g, tags: res.tags });
            setTagInput(null);
        } catch (e) {
            const err = e as ApiError;
            toast.error(err.field('tags') ?? err.message, err.ref);
        } finally {
            setBusy(false);
        }
    };
    const latest = g.stay_history[0];

    return (
        <SidePanel title={g.name} onClose={onClose} headerExtra={can.update && <Button size="sm" variant="outline" icon="pencil" onClick={() => onEdit(g)}>{t('ui.edit')}</Button>}>
            <div className="sp-section" style={{ borderTop: 0, paddingTop: 0 }}>
                <div className="row-between" style={{ alignItems: 'flex-start' }}>
                    <div className="row" style={{ alignItems: 'flex-start' }}>
                        <Flag code={g.nationality} large />
                        <div className="stack" style={{ gap: 4 }}>
                            <span><span className="muted">{t('guests.guest_id')}:</span> <strong>{g.number}</strong></span>
                            <span className="row text-sm" style={{ flexWrap: 'wrap' }}>
                                {g.phone && <span><Icon name="phone" size={14} /> {g.phone}</span>}
                                {g.email && <span><Icon name="mail" size={14} /> {g.email}</span>}
                            </span>
                        </div>
                    </div>
                    <Badge status={g.status === 'new' ? 'inactive' : g.status}>{t(`guests.status.${g.status}`)}</Badge>
                </div>
                <div className="stay-box" style={{ marginTop: 12 }}>
                    <div><span className="field-label">{t('guests.fields.nationality_iso2')}</span><strong>{g.nationality ?? '—'}</strong></div>
                    <div><span className="field-label">{t('guests.fields.id_type')}</span><strong>{g.id_type ? t(`guests.id_types.${g.id_type}`) : '—'}</strong></div>
                    <div><span className="field-label">{t('guests.fields.id_number')}</span><strong title={can.update ? undefined : t('guests.id_hidden')}>{g.id_number ?? '—'}</strong></div>
                </div>
            </div>
            <div className="sp-section">
                <div className="row-between"><h3 style={{ margin: 0 }}>{t('guests.tags')}</h3>
                    {can.update && tagInput === null && <Button size="sm" variant="outline" icon="plus" onClick={() => setTagInput('')}>{t('guests.add_tag')}</Button>}</div>
                <div className="row" style={{ flexWrap: 'wrap', marginTop: 10 }}>
                    {g.tags.length === 0 && tagInput === null && <span className="muted text-sm">{t('guests.no_tags')}</span>}
                    {g.tags.map((tag) => (
                        <span key={tag} className="chip">{tag}{can.update && <button type="button" className="chip-x" aria-label={`${t('guests.remove_tag')} ${tag}`} title={t('guests.remove_tag')} disabled={busy} onClick={() => saveTags(g.tags.filter((x) => x !== tag))}><Icon name="x" size={12} /></button>}</span>
                    ))}
                </div>
                {tagInput !== null && <form className="row" style={{ marginTop: 10 }} onSubmit={(e) => { e.preventDefault(); if (tagInput.trim()) saveTags([...g.tags, tagInput.trim()]); }}>
                    <Input size="sm" autoFocus maxLength={30} aria-label={t('guests.add_tag')} placeholder={t('guests.tag_placeholder')} value={tagInput} onChange={(e) => setTagInput(e.target.value)}
                        onKeyDown={(e) => e.key === 'Escape' && setTagInput(null)} />
                    <Button size="sm" type="submit" variant="primary" loading={busy} disabled={!tagInput.trim()}>{t('ui.save')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => setTagInput(null)}>{t('ui.cancel')}</Button>
                </form>}
            </div>
            <div className="panel-tabs" style={{ padding: '0 20px' }}>
                <Tabs active={tab} onChange={setTab} items={(['stays', 'notes', 'documents', 'preferences'] as const).map((k) => ({ key: k, label: t(`guests.panel_tabs.${k}`) }))} />
            </div>
            <div className="sp-section">
                {tab === 'stays' && (g.stay_history.length === 0 ? <p className="muted">{t('guests.no_stays')}</p> : <>
                    <table className="table compact">
                        <thead><tr><th>{t('guests.columns.date')}</th><th>{t('guests.columns.room')}</th><th>{t('guests.columns.type')}</th><th className="num">{t('guests.columns.amount')}</th><th>{t('guests.columns.status')}</th></tr></thead>
                        <tbody>{g.stay_history.map((s) => (
                            <tr key={s.id} className={can.reservations ? 'clickable' : undefined} onClick={can.reservations ? () => { window.location.href = propertyUrl(`/reservations/${s.id}`); } : undefined}>
                                <td className="nowrap">{date(s.check_in)}</td><td>{s.unit ?? '—'}</td><td className="cell-clip" title={s.rate_plan ?? ''}>{s.rate_plan ?? s.room_type ?? '—'}</td>
                                <td className="num">{money(s.total, s.currency)}</td><td><Badge size="sm" status={s.status}>{t(`reservations.status.${s.status}`)}</Badge></td>
                            </tr>
                        ))}</tbody>
                    </table>
                    {can.reservations && <div className="center" style={{ marginTop: 10 }}><a className="card-link" href={propertyUrl(`/reservations?guest=${g.id}`)}>{t('guests.view_all_stays')} <Icon name="arrow-right" size={14} /></a></div>}
                </>)}
                {tab === 'notes' && <GuestNotes g={g} setG={setG} canAdd={can.update} />}
                {tab === 'documents' && <GuestDocuments g={g} setG={setG} canAdd={can.update} />}
                {tab === 'preferences' && <KeyValue items={[
                    { label: t('guests.fields.preferences'), value: g.preferences },
                    { label: t('guests.fields.notes'), value: g.notes_text },
                    { label: t('guests.fields.guest_type'), value: t(`guests.types.${g.guest_type}`) },
                    { label: t('guests.fields.company_name'), value: g.company_name },
                    { label: t('guests.fields.date_of_birth'), value: g.date_of_birth ? date(g.date_of_birth) : null },
                    { label: t('guests.fields.marketing_consent'), value: g.marketing_consent ? t('ui.yes') : t('ui.no') },
                    { label: t('guests.fields.created_at'), value: dateTime(g.created_at) },
                ]} />}
            </div>
            <div className="sp-section">
                <h3>{t('ui.quick_actions')}</h3>
                <div className="action-grid">
                    {can.reserve && <LinkButton variant="outline" icon="plus" href={propertyUrl(`/reservations/new?guest=${g.id}`)}>{t('guests.quick.new_reservation')}</LinkButton>}
                    {can.folio && latest && <LinkButton variant="outline" icon="file-text" href={propertyUrl(`/reservations/${latest.id}?tab=payments`)}>{t('guests.quick.view_folio')}</LinkButton>}
                    <LinkButton variant="outline" icon="mail" href={g.email ? `mailto:${g.email}` : undefined}>{t('guests.quick.send_email')}</LinkButton>
                    <Dropdown items={[
                        ...(can.update ? [{ label: t('guests.quick.edit'), icon: 'pencil', onClick: () => onEdit(g) }] : []),
                        ...(can.reservations ? [{ label: t('guests.view_all_stays'), icon: 'calendar-check', href: propertyUrl(`/reservations?guest=${g.id}`) }] : []),
                    ]} trigger={(toggle) => <Button variant="outline" iconRight="chevron-down" onClick={toggle}>{t('guests.quick.more')}</Button>} />
                </div>
            </div>
        </SidePanel>
    );
}

function GuestNotes({ g, setG, canAdd }: { g: GuestDetail; setG: (g: GuestDetail) => void; canAdd: boolean }) {
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const add = async () => {
        if (!body.trim() || busy) return;
        setBusy(true);
        try {
            const res = await http.post<{ message: string; note: GuestDetail['notes'][number] }>(propertyApiUrl(`/guests/${g.id}/notes`), { body });
            toast.success(res.message);
            setG({ ...g, notes: [res.note, ...g.notes] });
            setBody('');
        } catch (e) {
            toast.error((e as ApiError).field('body') ?? (e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="stack">
            {canAdd && <form className="stack" onSubmit={(e) => { e.preventDefault(); add(); }}>
                <Textarea aria-label={t('reservations.detail.add_note')} rows={2} maxLength={2000} value={body} placeholder={t('reservations.detail.note_placeholder')} onChange={(e) => setBody(e.target.value)} />
                <div><Button type="submit" size="sm" variant="primary" icon="plus" loading={busy} disabled={!body.trim()}>{t('reservations.detail.add_note')}</Button></div>
            </form>}
            {g.notes.length === 0 ? <p className="muted">{t('guests.no_notes')}</p> : <ul className="list-plain">{g.notes.map((n) => (
                <li key={n.id} className="activity"><span className="a-icon tone-amber"><Icon name="sticky-note" size={16} /></span>
                    <div className="grow"><div className="a-title note-body">{n.body}</div><div className="a-sub">{n.user ?? '—'}</div></div><span className="a-time">{dateTime(n.at)}</span></li>
            ))}</ul>}
        </div>
    );
}

function GuestDocuments({ g, setG, canAdd }: { g: GuestDetail; setG: (g: GuestDetail) => void; canAdd: boolean }) {
    const [type, setType] = useState('passport');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const upload = async (file: File) => {
        setBusy(true);
        const form = new FormData();
        form.append('file', file);
        form.append('type', type);
        try {
            const res = await http.post<{ message: string; document: GuestDetail['documents'][number] }>(propertyApiUrl(`/guests/${g.id}/documents`), form);
            toast.success(res.message);
            setG({ ...g, documents: [res.document, ...g.documents] });
        } catch (e) {
            toast.error((e as ApiError).field('file') ?? (e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };
    return (
        <div className="stack">
            {canAdd && <div className="row">
                <Select size="sm" aria-label={t('guests.fields.document_type')} value={type} options={DOC_TYPES.map((v) => ({ value: v, label: t(`guests.document_types.${v}`) }))} onChange={(e) => setType(e.target.value)} />
                <Button size="sm" variant="outline" icon="upload" loading={busy} onClick={() => input.current?.click()}>{t('reservations.detail.upload_document')}</Button>
                <input ref={input} type="file" hidden accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
            </div>}
            {g.documents.length === 0 ? <p className="muted">{t('guests.no_documents')}</p> : <ul className="list-plain">{g.documents.map((d) => (
                <li key={d.id} className="activity"><span className="a-icon tone-slate"><Icon name="file-text" size={16} /></span>
                    <div className="grow"><a className="a-title" href={propertyApiUrl(`/guests/${g.id}/documents/${d.id}`)}>{d.name}</a><div className="a-sub">{t(`guests.document_types.${d.type}`)} · {Math.max(1, Math.round(d.size / 1024))} KB</div></div>
                    <span className="a-time">{dateTime(d.at)}</span></li>
            ))}</ul>}
        </div>
    );
}
