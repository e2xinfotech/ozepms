import { useEffect, useState } from 'react';
import { Alert, Button, Card, Input, Select } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';
import { act, fieldError } from '../../_accommodation/shared';
import type { Listings, MappingRoom } from './types';

interface Hold { room_id: string; rate_id: string | null; days: { date: string; availability: number | null; price: string | null; closed: boolean; min_los: number | null }[] }

const iso = (offset: number) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);

/** Practice tools: what the Test Channel received, a booking sender and an outage switch. */
export function TestChannelTab({ base, listings, mapping }: { base: string; listings: Listings; mapping: MappingRoom[] }) {
    const mappedRooms = mapping.filter((r) => r.external_room_id);
    const [holds, setHolds] = useState<Hold[] | null>(null);
    const [form, setForm] = useState({ room_id: mappedRooms[0]?.external_room_id ?? '', rate_id: '', check_in: iso(3), check_out: iso(5), adults: '2', price: '3500', first_name: 'Anna', last_name: 'Keller' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState('');
    const [version, setVersion] = useState(0);

    useEffect(() => {
        http.get<{ holds: Hold[] }>(propertyApiUrl(`${base}/test-channel/holds`)).then((d) => setHolds(d.holds)).catch(() => setHolds([]));
    }, [base, version]);

    const rates = listings.rates.filter((r) => r.room_id === form.room_id);
    const send = async () => {
        setBusy('send');
        setError(null);
        const res = await act(() => http.post<{ message: string }>(propertyApiUrl(`${base}/test-channel/bookings`), { ...form, rate_id: form.rate_id || rates[0]?.id || null, adults: Number(form.adults) }), setError);
        setBusy('');
        if (res) setVersion((v) => v + 1);
    };
    const outage = async () => {
        setBusy('outage');
        await act(() => http.post<{ message: string }>(propertyApiUrl(`${base}/test-channel/outage`)));
        setBusy('');
    };
    const message = error && !fieldError(error, 'room_id') ? (Object.values(error.fields)[0]?.[0] ?? error.message) : null;

    return (
        <div className="stack" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div className="ch-two">
                <Card title={t('channels.test_channel.send_booking')}>
                    <p className="muted text-sm">{t('channels.test_channel.send_booking_text')}</p>
                    {message && <Alert tone="danger">{message}</Alert>}
                    <div className="form-grid">
                        <Select fieldClass="span-6" label={t('channels.test_channel.room')} value={form.room_id} options={mappedRooms.map((r) => ({ value: r.external_room_id, label: `${r.name} (${r.external_room_id})` }))} onChange={(e) => setForm({ ...form, room_id: e.target.value, rate_id: '' })} error={fieldError(error, 'room_id')} />
                        <Select fieldClass="span-6" label={t('channels.test_channel.rate')} value={form.rate_id || rates[0]?.id || ''} options={rates.map((r) => ({ value: r.id, label: r.id }))} onChange={(e) => setForm({ ...form, rate_id: e.target.value })} />
                        <Input fieldClass="span-6" type="date" label={t('channels.test_channel.check_in')} value={form.check_in} onChange={(e) => setForm({ ...form, check_in: e.target.value })} error={fieldError(error, 'check_in')} />
                        <Input fieldClass="span-6" type="date" label={t('channels.test_channel.check_out')} value={form.check_out} onChange={(e) => setForm({ ...form, check_out: e.target.value })} error={fieldError(error, 'check_out')} />
                        <Input fieldClass="span-6" type="number" min={1} label={t('channels.test_channel.adults')} value={form.adults} onChange={(e) => setForm({ ...form, adults: e.target.value })} />
                        <Input fieldClass="span-6" type="number" min={0} step="0.01" label={t('channels.test_channel.price')} value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} error={fieldError(error, 'price')} />
                        <Input fieldClass="span-6" label={t('channels.test_channel.first_name')} value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} error={fieldError(error, 'first_name')} />
                        <Input fieldClass="span-6" label={t('channels.test_channel.last_name')} value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} error={fieldError(error, 'last_name')} />
                        <div className="span-12"><Button variant="primary" icon="send" loading={busy === 'send'} disabled={!form.room_id} onClick={send}>{t('channels.test_channel.send')}</Button></div>
                    </div>
                </Card>
                <Card title={t('channels.test_channel.outage')}>
                    <p className="muted text-sm">{t('channels.test_channel.outage_text')}</p>
                    <Button icon="alert-triangle" loading={busy === 'outage'} onClick={outage}>{t('channels.test_channel.outage')}</Button>
                </Card>
            </div>
            <Card title={t('channels.test_channel.holds')} actions={<Button size="sm" icon="refresh" onClick={() => setVersion((v) => v + 1)}>{t('channels.actions.refresh')}</Button>}>
                <p className="muted text-sm">{t('channels.test_channel.holds_text')}</p>
                {holds && holds.length === 0 && <p className="muted">{t('channels.test_channel.nothing_yet')}</p>}
                {(holds ?? []).map((h) => (
                    <div className="ch-holds" key={`${h.room_id}|${h.rate_id}`}>
                        <table>
                            <thead><tr><th>{h.rate_id ?? h.room_id}</th>{h.days.map((d) => <th key={d.date}>{d.date.slice(5)}</th>)}</tr></thead>
                            <tbody><tr><td>{h.rate_id ? t('channels.col.markup') : t('channels.col.channel_room')}</td>
                                {h.days.map((d) => <td key={d.date} className={d.closed ? 'closed' : ''}>{d.closed ? t('channels.test_channel.sold_out') : h.rate_id ? (d.price ?? '—') : (d.availability ?? '—')}</td>)}</tr></tbody>
                        </table>
                    </div>
                ))}
            </Card>
        </div>
    );
}
