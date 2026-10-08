import { Alert, EmptyState, LinkButton } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

interface Props { name: string; code: string; rejected: boolean; note: string | null; requested_at: string | null }

/** Shown to the owner while a self-registered property waits for approval, or after it was refused. */
function PendingApprovalPage({ name, code, rejected, note, requested_at }: Props) {
    return (
        <div className="content">
            <EmptyState
                icon={rejected ? 'circle-alert' : 'clock'}
                title={rejected ? t('approvals.rejected_title') : t('approvals.pending_title')}
                text={rejected ? t('approvals.rejected_text', { name, code }) : t('approvals.pending_text', { name, code })}
                action={<LinkButton variant="outline" icon="hotel" href="/properties">{t('property.choose_title')}</LinkButton>} />
            {requested_at && !rejected && <p className="muted" style={{ textAlign: 'center' }}>{t('approvals.pending_since', { date: dateTime(requested_at) })}</p>}
            {rejected && note && <Alert tone="danger">{t('approvals.reason')}: {note}</Alert>}
        </div>
    );
}

createPage(PendingApprovalPage);
