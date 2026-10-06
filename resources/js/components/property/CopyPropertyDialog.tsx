import { useEffect, useState } from 'react';
import { Alert, Button, Checkbox, Input, Modal, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

/**
 * "Copy Property" dialog. `endpoint` is the copy endpoint of the property being copied;
 * on success the browser opens the new property's page returned by the server.
 */
export function CopyPropertyDialog({ open, endpoint, sourceName, onClose }: { open: boolean; endpoint: string; sourceName: string; onClose: () => void }) {
    const [name, setName] = useState('');
    const [rooms, setRooms] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        if (open) { setName(t('property.copy_name_default', { name: sourceName })); setError(null); setBusy(false); }
    }, [open, sourceName]);

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string; redirect: string }>(endpoint, { name, include_rooms: rooms });
            window.location.href = res.redirect;
        } catch (e) {
            const err = e as ApiError;
            setError(err);
            if (!err.field('name')) toast.error(err.message, err.ref);
            setBusy(false);
        }
    };

    return (
        <Modal open={open} title={t('property.copy_title')} onClose={onClose} footer={<>
            <Button onClick={onClose}>{t('ui.cancel')}</Button>
            <Button variant="primary" icon="copy" loading={busy} disabled={name.trim() === ''} onClick={submit}>{t('property.copy_submit')}</Button>
        </>}>
            <div className="stack" style={{ gap: 16 }}>
                <Alert tone="info">{t('property.copy_sub')}</Alert>
                <Input label={t('property.copy_name')} required value={name} maxLength={150} error={error?.field('name')} onChange={(e) => setName(e.target.value)} />
                <div>
                    <Checkbox label={t('property.copy_rooms')} checked={rooms} onChange={(e) => setRooms(e.target.checked)} />
                    <p className="field-hint" style={{ marginTop: 4, marginLeft: 28 }}>{t('property.copy_rooms_hint')}</p>
                </div>
            </div>
        </Modal>
    );
}
