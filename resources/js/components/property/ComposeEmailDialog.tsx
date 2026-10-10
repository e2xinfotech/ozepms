import { useState } from 'react';
import { Alert, Button, Input, Modal, Select, Textarea, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

/** "Send e-mail" to a guest: the booking confirmation again (reservations) or a message written here. */
export function ComposeEmailDialog({ open, onClose, url, to, withConfirmation, subject }: {
    open: boolean; onClose: () => void; url: string; to: string | null; withConfirmation: boolean; subject?: string;
}) {
    const [type, setType] = useState<'message' | 'confirmation'>(withConfirmation ? 'confirmation' : 'message');
    const [form, setForm] = useState({ subject: subject ?? '', body: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const send = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string }>(url, withConfirmation ? { type, ...form } : form);
            toast.success(res.message);
            onClose();
        } catch (e) {
            setError(e as ApiError);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal open={open} onClose={onClose} title={t('mailsettings.compose_title')} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="send" loading={busy} disabled={!to || (type === 'message' && (!form.subject.trim() || !form.body.trim()))} onClick={send}>{t('mailsettings.compose_send')}</Button>
        </>}>
            <div className="stack">
                {!to && <Alert tone="warn">{t('mailsettings.errors.no_address')}</Alert>}
                {to && <p className="muted text-sm">{t('mailsettings.compose_to', { to })}</p>}
                {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
                {withConfirmation && (
                    <Select label={t('mailsettings.compose_type')} value={type} onChange={(e) => setType(e.target.value as 'message' | 'confirmation')}
                        options={[{ value: 'confirmation', label: t('mailsettings.compose_confirmation') }, { value: 'message', label: t('mailsettings.compose_message') }]} />
                )}
                {type === 'confirmation'
                    ? <p className="muted text-sm">{t('mailsettings.compose_confirmation_hint')}</p>
                    : <>
                        <Input label={t('mailsettings.log_subject')} required maxLength={200} value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} error={error?.field('subject')} />
                        <Textarea label={t('mailsettings.compose_body')} required rows={8} maxLength={5000} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} error={error?.field('body')} />
                    </>}
            </div>
        </Modal>
    );
}
