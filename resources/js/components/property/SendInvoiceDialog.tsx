import { useState } from 'react';
import { Alert, Button, Input, Modal, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { propertyApiUrl } from '@/lib/page';

/** Sends an invoice or credit note by e-mail, to the guest's address or to another one. */
export function SendInvoiceDialog({ invoiceId, number, onClose }: { invoiceId: string; number: string; onClose: () => void }) {
    const [to, setTo] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const send = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string }>(propertyApiUrl(`/invoices/${invoiceId}/email`), { to: to.trim() || null });
            toast.success(res.message);
            onClose();
        } catch (e) {
            setError(e as ApiError);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal open onClose={onClose} title={`${t('mailsettings.invoice_dialog_title')} · ${number}`} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="send" loading={busy} onClick={send}>{t('mailsettings.compose_send')}</Button>
        </>}>
            <div className="stack">
                {error && !error.field('to') && <Alert tone="danger">{error.message}</Alert>}
                <Input label={t('mailsettings.invoice_to')} type="email" optional autoFocus value={to} onChange={(e) => setTo(e.target.value)}
                    hint={t('mailsettings.invoice_to_hint')} error={error?.field('to')} />
            </div>
        </Modal>
    );
}
