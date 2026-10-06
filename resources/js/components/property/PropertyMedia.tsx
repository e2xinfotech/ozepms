import { useRef, useState } from 'react';
import { Button, Field, FormSection, Icon, toast } from '@/components/ui';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import type { PropertyDetail } from './types';

type Kind = 'logo' | 'cover';

/**
 * "Logo & Photo" block of the property forms. `endpoint` is the media endpoint without the kind,
 * e.g. /web-api/p/P1001/settings/media; each upload saves at once.
 */
export function PropertyMedia({ endpoint, logo, image, disabled, onChange }: {
    endpoint: string; logo: string | null; image: string | null; disabled?: boolean; onChange?: (p: PropertyDetail) => void;
}) {
    const [urls, setUrls] = useState<Record<Kind, string | null>>({ logo, cover: image });
    const [busy, setBusy] = useState<Kind | null>(null);
    const inputs = { logo: useRef<HTMLInputElement>(null), cover: useRef<HTMLInputElement>(null) };

    const upload = async (kind: Kind, file: File | undefined) => {
        if (!file) return;
        setBusy(kind);
        try {
            const res = await http.upload<{ message: string; property: PropertyDetail }>(`${endpoint}/${kind}`, file);
            setUrls({ logo: res.property.logo, cover: res.property.image });
            onChange?.(res.property);
            toast.success(res.message);
        } catch (e) {
            const err = e as ApiError;
            toast.error(err.field('image') ?? err.message, err.ref);
        } finally {
            setBusy(null);
        }
    };

    const remove = async (kind: Kind) => {
        setBusy(kind);
        try {
            const res = await http.delete<{ message: string; property: PropertyDetail }>(`${endpoint}/${kind}`);
            setUrls({ logo: res.property.logo, cover: res.property.image });
            onChange?.(res.property);
        } catch (e) {
            toast.error((e as ApiError).message, (e as ApiError).ref);
        } finally {
            setBusy(null);
        }
    };

    const tile = (kind: Kind) => (
        <Field className="span-6" label={t(`property.media.${kind}`)} optional hint={disabled ? undefined : t('property.media.hint')}>
            <div className={`media-tile ${kind}`}>
                {urls[kind] ? <img src={urls[kind] ?? ''} alt={t(`property.media.${kind}`)} /> : <span className="media-empty"><Icon name={kind === 'logo' ? 'image' : 'images'} size={28} /></span>}
                {!disabled && <div className="media-actions">
                    <input ref={inputs[kind]} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => { void upload(kind, e.target.files?.[0]); e.target.value = ''; }} />
                    <Button size="sm" icon="upload" loading={busy === kind} disabled={disabled} onClick={() => inputs[kind].current?.click()} title={t('property.media.upload')}>{t('property.media.upload')}</Button>
                    {urls[kind] && <Button size="sm" variant="ghost" icon="trash" disabled={disabled || busy === kind} onClick={() => void remove(kind)} title={t('ui.delete')} aria-label={t('ui.delete')} />}
                </div>}
            </div>
        </Field>
    );

    return (
        <FormSection title={t('property.media.title')}>
            {tile('logo')}
            {tile('cover')}
        </FormSection>
    );
}
