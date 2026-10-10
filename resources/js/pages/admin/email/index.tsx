import { Alert, PageHeader } from '@/components/ui';
import { EmailCard, type EmailSettings } from '@/components/property/EmailCard';
import { createPage } from '@/lib/boot';
import { t } from '@/lib/i18n';

/** Platform staff → the platform's default SMTP account. */
function EmailPage({ email, can_update }: { email: EmailSettings; can_update: boolean }) {
    return (
        <div className="content">
            <PageHeader title={t('mailsettings.platform_title')} description={t('mailsettings.platform_description')} />
            {!can_update && <Alert tone="info">{t('property.settings_read_only')}</Alert>}
            <EmailCard initial={email} disabled={!can_update} url="/web-api/admin/email" scope="platform" />
        </div>
    );
}

createPage(EmailPage);
