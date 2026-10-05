import { useState, type FormEvent } from 'react';
import { Logo } from '@/components/shell/Logo';
import { Alert, Button, Input } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

interface Props { token: string; email: string; password_rules: { min: number } }

function ResetPasswordPage({ token, email: initialEmail, password_rules }: Props) {
    const [email, setEmail] = useState(initialEmail);
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ redirect: string }>('/web-api/auth/reset-password', { token, email, password, password_confirmation: confirmation });
            window.location.href = res.redirect;
        } catch (err) {
            setError(err as ApiError);
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} noValidate>
            <Logo />
            <h1>{t('auth.reset_title')}</h1>
            <p className="auth-sub">{t('auth.reset_sub', { min: password_rules.min })}</p>
            <div className="stack">
                {error && !Object.keys(error.fields).length && <Alert tone="danger">{error.message}</Alert>}
                {error?.field('token') && <Alert tone="danger">{error.field('token')}</Alert>}
                <Input label={t('auth.email')} type="email" icon="mail" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
                <Input label={t('auth.new_password')} type="password" icon="lock" autoComplete="new-password" required autoFocus value={password} onChange={(e) => setPassword(e.target.value)} error={error?.field('password')} />
                <Input label={t('auth.confirm_password')} type="password" icon="lock" autoComplete="new-password" required value={confirmation} onChange={(e) => setConfirmation(e.target.value)} error={error?.field('password_confirmation')} />
                <Button type="submit" variant="primary" size="lg" block loading={busy}>{t('auth.reset_button')}</Button>
                <a href="/login" className="strong" style={{ textAlign: 'center' }}>{t('auth.back_to_login')}</a>
            </div>
        </form>
    );
}

createPage(ResetPasswordPage);
