import { Fragment, useState } from 'react';
import { Alert, Badge, Button, Card, Input, Select } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act } from '../../_accommodation/shared';
import type { ConnectionDetail, Listings, MappingRoom } from './types';

/** Room type ↔ channel room and rate plan ↔ channel rate, with an optional price markup. */
export function MappingTab({ c, mapping, listings }: { c: ConnectionDetail; mapping: MappingRoom[]; listings: Listings }) {
    const [rows, setRows] = useState(mapping);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState<ApiError | null>(null);
    const listed = listings.rooms.length > 0;

    const setRoom = (i: number, v: string) => setRows((l) => l.map((r, k) => (k === i ? { ...r, external_room_id: v } : r)));
    const setRate = (i: number, j: number, patch: Partial<MappingRoom['rates'][number]>) =>
        setRows((l) => l.map((r, k) => (k === i ? { ...r, rates: r.rates.map((x, m) => (m === j ? { ...x, ...patch } : x)) } : r)));

    const suggest = async () => {
        setBusy('suggest');
        const res = await act(() => http.get<{ rooms: Record<string, string>; rates: Record<string, string>; message: string }>(propertyApiUrl(`/channels/${c.id}/suggest`)));
        setBusy('');
        if (!res) return;
        setRows((l) => l.map((r) => ({
            ...r, external_room_id: res.rooms[r.room_type_id] ?? r.external_room_id,
            rates: r.rates.map((x) => ({ ...x, external_rate_id: res.rates[String(x.product_id)] ?? x.external_rate_id })),
        })));
    };

    const save = async () => {
        setBusy('save');
        setError(null);
        const body = {
            rooms: Object.fromEntries(rows.map((r) => [r.room_type_id, r.external_room_id])),
            rates: rows.flatMap((r) => r.rates.map((x) => ({ product_id: x.product_id, external_rate_id: x.external_rate_id, markup_type: x.markup_type, markup_value: x.markup_type === 'none' || x.markup_value === '' ? null : x.markup_value }))),
        };
        const res = await act(() => http.put<{ message: string }>(propertyApiUrl(`/channels/${c.id}/mapping`), body), setError);
        setBusy('');
        if (res) window.location.reload();
    };

    const roomOptions = listings.rooms.map((r) => ({ value: r.id, label: `${r.name} (${r.id})` }));
    const message = error ? (Object.values(error.fields)[0]?.[0] ?? error.message) : null;

    return (
        <Card title={t('channels.tabs.mapping')} actions={<>
            {listed && <Button icon="sparkles" loading={busy === 'suggest'} onClick={suggest}>{t('channels.actions.suggest')}</Button>}
            <Button variant="primary" icon="save" loading={busy === 'save'} onClick={save}>{t('channels.actions.save_mapping')}</Button>
        </>}>
            <p className="muted text-sm">{t('channels.mapping.intro')}</p>
            {message && <Alert tone="danger">{message}</Alert>}
            <div className="ch-scroll">
                <table className="ch-map">
                    <thead><tr><th>{t('channels.col.room_type')} / {t('channels.col.rate_plan')}</th><th>{t('channels.col.channel_room')} / {t('channels.col.channel_rate')}</th><th>{t('channels.col.markup')}</th></tr></thead>
                    <tbody>
                        {rows.map((r, i) => (
                            <Fragment key={r.room_type_id}>
                                <tr className="room">
                                    <td>{r.name} <span className="muted num">({r.code})</span></td>
                                    <td>{listed
                                        ? <Select aria-label={t('channels.col.channel_room')} value={r.external_room_id} placeholder={t('channels.mapping.not_mapped')} options={roomOptions} onChange={(e) => setRoom(i, e.target.value)} />
                                        : <Input aria-label={t('channels.col.channel_room')} placeholder={t('channels.mapping.free_text')} maxLength={64} value={r.external_room_id} onChange={(e) => setRoom(i, e.target.value)} />}</td>
                                    <td />
                                </tr>
                                {r.rates.map((x, j) => {
                                    const rateOptions = listings.rates.filter((l) => l.room_id === r.external_room_id).map((l) => ({ value: l.id, label: `${l.name} (${l.id})` }));
                                    return (
                                        <tr className="rate" key={x.product_id}>
                                            <td>{x.name} <span className="muted num">({x.code})</span>{!x.sellable && <> <Badge size="sm" tone="slate" >{t('channels.mapping.no_channel_sale')}</Badge></>}</td>
                                            <td>{listed
                                                ? <Select aria-label={t('channels.col.channel_rate')} value={x.external_rate_id} placeholder={t('channels.mapping.not_mapped')} disabled={!r.external_room_id} options={rateOptions} onChange={(e) => setRate(i, j, { external_rate_id: e.target.value })} />
                                                : <Input aria-label={t('channels.col.channel_rate')} placeholder={t('channels.mapping.free_text')} disabled={!r.external_room_id} maxLength={64} value={x.external_rate_id} onChange={(e) => setRate(i, j, { external_rate_id: e.target.value })} />}</td>
                                            <td><div className="ch-markup">
                                                <Select aria-label={t('channels.col.markup')} value={x.markup_type} disabled={!x.external_rate_id}
                                                    options={[{ value: 'none', label: t('channels.mapping.markup_none') }, { value: 'percent', label: t('channels.mapping.markup_percent') }, { value: 'fixed', label: t('channels.mapping.markup_fixed') }]}
                                                    onChange={(e) => setRate(i, j, { markup_type: e.target.value as 'none' | 'percent' | 'fixed' })} />
                                                {x.markup_type !== 'none' && <Input aria-label={t('channels.col.markup')} type="number" step="0.01" value={x.markup_value} onChange={(e) => setRate(i, j, { markup_value: e.target.value })} />}
                                            </div></td>
                                        </tr>
                                    );
                                })}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
