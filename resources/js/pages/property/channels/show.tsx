import { useState } from 'react';
import { Alert, Badge, Button, ConfirmDialog, Modal, PageHeader, PillTabs, Textarea } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime, relative } from '@/lib/format';
import { http, navigateWithQuery } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl, propertyUrl } from '@/lib/page';
import { act } from '../_accommodation/shared';
import { BookingsTab } from './_components/BookingsTab';
import { LogsTab } from './_components/LogsTab';
import { MappingTab } from './_components/MappingTab';
import { OverviewTab } from './_components/OverviewTab';
import { TestChannelTab } from './_components/TestChannelTab';
import { APPROVAL_TONE, STATUS_TONE, type ConnectionDetail, type Listings, type MappingRoom } from './_components/types';

interface Props { connection: ConnectionDetail; mapping: MappingRoom[]; listings: Listings; is_test: boolean; tab: string }

/** One channel: overview, mapping, sync log, received bookings and (Test Channel) practice tools. */
function ChannelPage({ connection: initial, mapping, listings, is_test, tab }: Props) {
    const [c, setC] = useState(initial);
    const [busy, setBusy] = useState('');
    const [confirm, setConfirm] = useState(false);
    const base = `/channels/${c.id}`;
    const title = c.name || t(`channels.providers.${c.provider}`);

    const run = async (key: string, call: () => Promise<{ message?: string }>, reload = true) => {
        setBusy(key);
        const res = await act(call);
        setBusy('');
        if (res && reload) window.location.reload();
    };
    const post = (path: string) => () => http.post<{ message: string }>(propertyApiUrl(`${base}${path}`));

    const tabs = ['overview', 'mapping', 'logs', 'bookings', ...(is_test ? ['test'] : [])];
    const approved = c.approval === 'approved';
    const active = approved && (c.status === 'active' || c.status === 'error');
    const [suspendOpen, setSuspendOpen] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <div className="content">
            <PageHeader back={propertyUrl('/channels')} title={title} description={`${t(`channels.providers.${c.provider}`)} · ${t('channels.hotel_id')}: ${c.hotel_id}`} actions={
                <div className="ch-actions">
                    {approved && <Button icon="check-circle" loading={busy === 'test'} onClick={() => run('test', post('/test'), false)}>{t('channels.actions.test')}</Button>}
                    {c.is_staff && c.requires_approval && approved && <Button variant="danger" icon="pause" onClick={() => setSuspendOpen(true)}>{t('channels.approval.suspend')}</Button>}
                    {c.is_staff && c.approval === 'suspended' && <Button variant="primary" icon="check-circle" loading={busy === 'unsuspend'} onClick={() => run('unsuspend', post('/unsuspend'))}>{t('channels.approval.unsuspend')}</Button>}
                    {active && <Button icon="refresh" loading={busy === 'sync'} onClick={() => run('sync', post('/sync'))}>{t('channels.actions.full_sync')}</Button>}
                    {active && <Button icon="pause" loading={busy === 'pause'} onClick={() => run('pause', post('/pause'))}>{t('channels.actions.pause')}</Button>}
                    {approved && c.status === 'paused' && <Button variant="primary" icon="refresh" loading={busy === 'resume'} onClick={() => run('resume', post('/resume'))}>{t('channels.actions.resume')}</Button>}
                    {c.status !== 'disconnected' && <Button variant="danger" icon="x" onClick={() => setConfirm(true)}>{t('channels.actions.disconnect')}</Button>}
                </div>
            } />
            <div className="ch-head-meta">
                <Badge tone={STATUS_TONE[c.status]}>{t(`channels.status.${c.status}`)}</Badge>
                {!approved && <Badge tone={APPROVAL_TONE[c.approval]}>{t(`channels.approval.${c.approval}`)}</Badge>}
                <span className="muted">{t('channels.col.last_success')}: {c.last_success_at ? `${relative(c.last_success_at)} (${dateTime(c.last_success_at)})` : t('channels.never')}</span>
                <span className="muted">{t('channels.mapping_summary', { rooms: c.rooms, rates: c.rates })}</span>
            </div>
            {c.approval === 'pending' && <Alert tone="warn">{t('channels.approval.banner_pending')}</Alert>}
            {c.approval === 'suspended' && <Alert tone="danger">{t('channels.approval.banner_suspended')}{c.approval_note && <> {t('channels.approval.reason')}: {c.approval_note}</>}</Alert>}
            {c.approval === 'rejected' && (
                <Alert tone="danger">{t('channels.approval.banner_rejected')}{c.approval_note && <> {t('channels.approval.reason')}: {c.approval_note}</>}{' '}
                    <Button size="sm" variant="outline" icon="send" loading={busy === 'again'} onClick={() => run('again', post('/request-approval'))}>{t('channels.approval.request_again')}</Button></Alert>
            )}
            {c.last_error && (c.status === 'error' || c.failures > 0) && (
                <Alert tone="danger">
                    <strong>{t('channels.col.last_error')}:</strong> {c.last_error}
                    {c.next_attempt_at && <> · {t('channels.overview.next_retry', { time: dateTime(c.next_attempt_at) })}</>}
                    {' '}<Button size="sm" variant="outline" icon="refresh" loading={busy === 'retry'} onClick={() => run('retry', post('/sync'))}>{t('channels.actions.retry')}</Button>
                </Alert>
            )}
            <PillTabs active={tab} onChange={(k) => navigateWithQuery({ tab: k === 'overview' ? null : k })} items={tabs.map((k) => ({ key: k, label: t(`channels.tabs.${k}`) }))} />
            {tab === 'overview' && <OverviewTab c={c} onSaved={(row) => setC({ ...c, ...row })} />}
            {tab === 'mapping' && <MappingTab c={c} mapping={mapping} listings={listings} />}
            {tab === 'logs' && <LogsTab base={base} />}
            {tab === 'bookings' && <BookingsTab base={base} isTest={is_test} />}
            {tab === 'test' && is_test && <TestChannelTab base={base} listings={listings} mapping={mapping} />}
            <Modal open={suspendOpen} title={t('channels.approval.suspend')} onClose={() => setSuspendOpen(false)} footer={<>
                <Button onClick={() => setSuspendOpen(false)}>{t('ui.cancel')}</Button>
                <Button variant="danger" loading={busy === 'suspend'} onClick={() => run('suspend', () => http.post<{ message: string }>(propertyApiUrl(`${base}/suspend`), { reason }))}>{t('channels.approval.suspend')}</Button>
            </>}>
                <p style={{ marginBottom: 12 }}>{t('channels.approval.suspend_confirm')}</p>
                <Textarea label={t('channels.approval.suspend_reason')} optional rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
            </Modal>
            <ConfirmDialog open={confirm} danger busy={busy === 'disconnect'} title={t('channels.actions.disconnect')} message={t('channels.actions.disconnect_confirm')}
                confirmLabel={t('channels.actions.disconnect')} onConfirm={() => run('disconnect', post('/disconnect'))} onClose={() => setConfirm(false)} />
        </div>
    );
}

createPage(ChannelPage);
