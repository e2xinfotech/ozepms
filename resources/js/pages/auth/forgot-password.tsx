import { useState, type FormEvent } from 'react';
import { Logo } from '@/components/shell/Logo';
import { Alert, Button, Input } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState<string | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ message: string }>('/web-api/auth/forgot-password', { email });
            setSent(res.message);
        } catch (err) {
            setError(err as ApiError);
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} noValidate>
            <Logo />
            <h1>{t('auth.forgot_title')}</h1>
            <p className="auth-sub">{t('auth.forgot_sub')}</p>
            <div className="stack">
                {sent && <Alert tone="success">{sent}</Alert>}
                {error && !error.fields.email && <Alert tone="danger">{error.message}</Alert>}
                <Input label={t('auth.email')} type="email" icon="mail" autoComplete="username" autoFocus required
                    placeholder={t('auth.email_placeholder')} value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
                <Button type="submit" variant="primary" size="lg" block loading={busy}>{t('auth.send_link')}</Button>
                <a href="/login" className="strong" style={{ textAlign: 'center' }}>{t('auth.back_to_login')}</a>
            </div>
        </form>
    );
}

createPage(ForgotPasswordPage);
