import { useState } from 'react';
import { Button, Modal, Textarea } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

/** Asks for the reason, then signs the platform user in as the given account. */
export function ImpersonateDialog({ userId, name, open, onClose }: { userId: string; name: string; open: boolean; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const start = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ redirect: string }>(`/web-api/admin/users/${userId}/impersonate`, { reason });
            window.location.href = res.redirect;
        } catch (e) {
            setError(e as ApiError);
            setBusy(false);
        }
    };

    return (
        <Modal open={open} title={t('impersonation.dialog_title', { name })} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="log-in" loading={busy} disabled={reason.trim().length < 5} onClick={start}>{t('impersonation.start')}</Button>
        </>}>
            <p className="muted" style={{ marginBottom: 12 }}>{t('impersonation.dialog_text', { minutes: 60 })}</p>
            <Textarea label={t('impersonation.reason')} required rows={3} maxLength={300} value={reason} placeholder={t('impersonation.reason_ph')}
                onChange={(e) => setReason(e.target.value)} error={error?.field('reason') ?? error?.field('user') ?? (error && !Object.keys(error.fields).length ? error.message : undefined)} />
        </Modal>
    );
}
