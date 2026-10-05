import { useState } from 'react';
import { Alert, Avatar, Button, Card, FormSection, Input, PageHeader, Select, toast } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';
import { payload } from '@/lib/page';

interface Profile { name: string; email: string; job_title: string | null; phone_e164: string | null; locale: string }

/** The signed-in user's own name, contact details and language. */
function ProfilePage({ profile }: { profile: Profile }) {
    const locales = payload().shell.locales;
    const [data, setData] = useState({ name: profile.name, job_title: profile.job_title ?? '', phone_e164: profile.phone_e164 ?? '', locale: profile.locale });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const save = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await http.put<{ message: string; profile: Profile }>('/web-api/account/profile', {
                name: data.name, job_title: data.job_title || null, phone_e164: data.phone_e164 || null, locale: data.locale,
            });
            if (res.profile.locale !== profile.locale) {
                window.location.reload();
                return;
            }
            toast.success(res.message);
        } catch (e) {
            setError(e as ApiError);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="content" style={{ maxWidth: 960 }}>
            <PageHeader title={t('auth.profile_title')} description={t('auth.profile_sub')} />
            <Card>
                <div className="row" style={{ gap: 16 }}>
                    <Avatar name={profile.name} size="lg" />
                    <div><div className="strong" style={{ fontSize: 18 }}>{profile.name}</div><div className="muted">{profile.email}</div></div>
                </div>
            </Card>
            {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
            <FormSection title={t('auth.profile_details')}>
                <Input fieldClass="span-6" label={t('users.name')} required value={data.name} onChange={(e) => setData({ ...data, name: e.target.value })} error={error?.field('name')} />
                <Input fieldClass="span-6" label={t('users.email')} type="email" icon="mail" value={profile.email} disabled hint={t('auth.email_change_hint')} />
                <Input fieldClass="span-6" label={t('users.job_title')} optional value={data.job_title} onChange={(e) => setData({ ...data, job_title: e.target.value })} error={error?.field('job_title')} />
                <Input fieldClass="span-6" label={t('users.phone')} optional icon="phone" placeholder="+971501234567" value={data.phone_e164} onChange={(e) => setData({ ...data, phone_e164: e.target.value })} error={error?.field('phone_e164')} hint={t('users.phone_hint')} />
                <Select fieldClass="span-6" label={t('users.language')} value={data.locale} options={Object.entries(locales).map(([value, label]) => ({ value, label }))} onChange={(e) => setData({ ...data, locale: e.target.value })} error={error?.field('locale')} />
            </FormSection>
            <div className="form-actions">
                <Button variant="primary" icon="save" loading={busy} onClick={save}>{t('ui.save_changes')}</Button>
            </div>
        </div>
    );
}

createPage(ProfilePage);
