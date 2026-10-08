import { useState } from 'react';
import { Alert, Badge, Button, Card, DataTable, EmptyState, Icon, Input, KpiCard, Modal, PageHeader, Select, type Column } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { relative } from '@/lib/format';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act, fieldError } from '../_accommodation/shared';
import { APPROVAL_TONE, STATUS_TONE, type ConnectionRow, type CredentialField } from './_components/types';

interface Provider { key: string; available: boolean; connected: boolean; requires_approval: boolean }
interface Props {
    connections: ConnectionRow[]; providers: Provider[]; credential_fields: Record<string, CredentialField[]>;
    kpi: { connected: number; last_sync: string | null; bookings: number; attention: number };
}

/** Channel Manager: connected channels with their health, and the add-channel dialog. */
function ChannelsPage({ connections, providers, credential_fields, kpi }: Props) {
    const [adding, setAdding] = useState(false);
    const free = providers.filter((p) => !p.connected);
    const label = (c: ConnectionRow) => c.name || t(`channels.providers.${c.provider}`);

    const columns: Column<ConnectionRow>[] = [
        { key: 'channel', header: t('channels.col.channel'), render: (c) => <span className="row"><Icon name="network" size={18} /><span><span className="cell-main">{label(c)}</span><br /><span className="cell-sub">{t(`channels.providers.${c.provider}`)}</span></span></span> },
        { key: 'hotel', header: t('channels.col.hotel_id'), render: (c) => <span className="num">{c.hotel_id}</span> },
        { key: 'status', header: t('channels.col.status'), render: (c) => <span title={c.last_error ?? undefined}><Badge size="sm" tone={STATUS_TONE[c.status]}>{t(`channels.status.${c.status}`)}</Badge>{c.approval !== 'approved' && <> <Badge size="sm" tone={APPROVAL_TONE[c.approval]}>{t(`channels.approval.${c.approval}`)}</Badge></>}{c.waiting && c.status === 'active' && <span className="muted text-sm"> · {t('channels.overview.waiting')}</span>}</span> },
        { key: 'mapping', header: t('channels.col.mapping'), render: (c) => t('channels.mapping_summary', { rooms: c.rooms, rates: c.rates }) },
        { key: 'last', header: t('channels.col.last_success'), render: (c) => c.last_success_at ? relative(c.last_success_at) : <span className="muted">{t('channels.never')}</span> },
        { key: 'actions', header: '', className: 'col-actions', render: (c) => <a className="btn btn-sm btn-outline" href={propertyUrl(`/channels/${c.id}`)}>{t('channels.open')}</a> },
    ];

    return (
        <div className="content">
            <PageHeader title={t('channels.title')} description={t('channels.subtitle')} actions={
                <Button variant="primary" icon="plus" onClick={() => setAdding(true)} disabled={!free.some((p) => p.available)}>{t('channels.add')}</Button>
            } />
            <div className="kpi-row">
                <KpiCard icon="network" tone="green" label={t('channels.kpi.connected')} value={kpi.connected} />
                <KpiCard icon="refresh" tone="blue" label={t('channels.kpi.last_sync')} value={kpi.last_sync ? relative(kpi.last_sync) : '—'} fit />
                <KpiCard icon="calendar-check" tone="violet" label={t('channels.kpi.bookings')} value={kpi.bookings} />
                <KpiCard icon="alert-triangle" tone={kpi.attention ? 'red' : 'green'} label={t('channels.kpi.attention')} value={kpi.attention} />
            </div>
            <DataTable columns={columns} rows={connections} rowKey={(c) => c.id} onRowClick={(c) => { window.location.href = propertyUrl(`/channels/${c.id}`); }}
                empty={<EmptyState icon="network" title={t('channels.none')} text={t('channels.none_text')} />} />
            <h3 className="ch-section-title">{t('channels.other_channels')}</h3>
            <div className="ch-cards">
                {providers.filter((p) => !p.connected).map((p) => (
                    <Card key={p.key}>
                        <div className="row"><Icon name="network" size={20} /><strong>{t(`channels.providers.${p.key}`)}</strong>{!p.available && <Badge size="sm" tone="slate">{t('channels.on_request')}</Badge>}</div>
                        <p className="muted text-sm">{t(`channels.provider_text.${p.key}`)}</p>
                        {!p.available && <p className="muted text-sm">{t('channels.on_request_text')}</p>}
                    </Card>
                ))}
            </div>
            {adding && <AddModal providers={free.filter((p) => p.available)} fields={credential_fields} onClose={() => setAdding(false)} />}
        </div>
    );
}

function AddModal({ providers, fields, onClose }: { providers: Provider[]; fields: Record<string, CredentialField[]>; onClose: () => void }) {
    const [provider, setProvider] = useState(providers[0]?.key ?? '');
    const [form, setForm] = useState({ name: '', external_hotel_id: '', credentials: {} as Record<string, string> });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);

    const needsApproval = providers.find((p) => p.key === provider)?.requires_approval ?? false;

    const save = async () => {
        setBusy(true);
        setError(null);
        const res = await act(() => http.post<{ message: string; connection: ConnectionRow }>(propertyApiUrl('/channels'), { provider, ...form, credentials: needsApproval ? {} : form.credentials }), setError);
        setBusy(false);
        if (res) window.location.href = propertyUrl(`/channels/${res.connection.id}`);
    };

    return (
        <Modal open title={t('channels.add')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('channels.save')}</Button>
        </>}>
            <div className="form-grid">
                {error && fieldError(error, 'provider') && <div className="span-12"><Alert tone="danger">{fieldError(error, 'provider')}</Alert></div>}
                <Select fieldClass="span-12" label={t('channels.provider')} value={provider} options={providers.map((p) => ({ value: p.key, label: t(`channels.providers.${p.key}`) }))} onChange={(e) => setProvider(e.target.value)} />
                <p className="muted text-sm span-12">{t(`channels.provider_text.${provider}`)}</p>
                <Input fieldClass="span-12" label={t('channels.name')} hint={t('channels.name_hint')} optional maxLength={80} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                <Input fieldClass="span-12" label={t('channels.hotel_id')} hint={t('channels.hotel_id_hint')} required maxLength={64} value={form.external_hotel_id} onChange={(e) => setForm({ ...form, external_hotel_id: e.target.value })} error={fieldError(error, 'external_hotel_id')} />
                {needsApproval && <div className="span-12"><Alert tone="info">{t('channels.approval.credentials_by_e2x')}</Alert></div>}
                {!needsApproval && (fields[provider] ?? []).map((f) => (
                    <Input key={f.key} fieldClass="span-12" label={t(`channels.fields.${f.key}`)} required={f.required} type={f.type === 'secret' ? 'password' : 'text'} autoComplete="off"
                        value={form.credentials[f.key] ?? ''} onChange={(e) => setForm({ ...form, credentials: { ...form.credentials, [f.key]: e.target.value } })} error={fieldError(error, `credentials.${f.key}`)} />
                ))}
            </div>
        </Modal>
    );
}

createPage(ChannelsPage);
