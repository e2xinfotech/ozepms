import { useState, type FormEvent } from 'react';
import { Logo } from '@/components/shell/Logo';
import { Alert, Button, Checkbox, Input } from '@/components/ui';
import { http, ApiError } from '@/lib/http';
import { createPage } from '@/lib/boot';
import { t } from '@/lib/i18n';
import { payload } from '@/lib/page';

function LoginPage() {
    const sso = payload().shell.sso;
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ redirect: string }>('/web-api/auth/login', { email, password, remember });
            window.location.href = res.redirect;
        } catch (err) {
            setError(err as ApiError);
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} noValidate>
            <Logo />
            <h1>{t('auth.welcome_back')}</h1>
            <p className="auth-sub">{t('auth.sign_in_sub')}</p>

            <div className="stack">
                {error && !error.fields.email && !error.fields.password && <Alert tone="danger">{error.message}</Alert>}
                <Input label={t('auth.email')} type="email" icon="mail" autoComplete="username" autoFocus required
                    placeholder={t('auth.email_placeholder')} value={email} onChange={(e) => setEmail(e.target.value)} error={error?.field('email')} />
                <Input label={t('auth.password_label')} type="password" icon="lock" autoComplete="current-password" required
                    placeholder={t('auth.password_placeholder')} value={password} onChange={(e) => setPassword(e.target.value)} error={error?.field('password')} />
                <div className="row-between">
                    <Checkbox label={t('auth.remember')} checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                    <a href="/forgot-password" className="strong">{t('auth.forgot')}</a>
                </div>
                <Button type="submit" variant="primary" size="lg" block loading={busy}>{t('auth.sign_in')}</Button>
            </div>

            {(sso.google || sso.microsoft) && (
                <>
                    <div className="divider-text">{t('auth.or_continue')}</div>
                    <div className="row" style={{ gap: 16 }}>
                        {sso.google && <a className="btn btn-secondary btn-lg grow" href="/auth/google">Google</a>}
                        {sso.microsoft && <a className="btn btn-secondary btn-lg grow" href="/auth/microsoft">Microsoft</a>}
                    </div>
                </>
            )}
        </form>
    );
}

createPage(LoginPage);
