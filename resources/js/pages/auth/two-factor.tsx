import { useState, type FormEvent } from 'react';
import { Logo } from '@/components/shell/Logo';
import { Alert, Button, Input } from '@/components/ui';
import { createPage } from '@/lib/boot';
import { http, type ApiError } from '@/lib/http';
import { t } from '@/lib/i18n';

/** Second step of sign-in: authenticator code or a recovery code. */
function TwoFactorPage() {
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const res = await http.post<{ redirect: string }>('/web-api/auth/two-factor', { code: code.trim() });
            window.location.href = res.redirect;
        } catch (err) {
            const apiError = err as ApiError;
            if (apiError.code === 'TWO_FACTOR_EXPIRED') {
                window.location.href = '/login';
                return;
            }
            setError(apiError);
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} noValidate>
            <Logo />
            <h1>{t('auth.two_factor_title')}</h1>
            <p className="auth-sub">{t('auth.two_factor_sub')}</p>
            <div className="stack">
                {error && !error.fields.code && <Alert tone="danger">{error.message}</Alert>}
                <Input label={t('auth.two_factor_code')} icon="shield-check" autoComplete="one-time-code" inputMode="text" autoFocus required maxLength={32}
                    placeholder="123456" value={code} onChange={(e) => setCode(e.target.value)} error={error?.field('code')} hint={t('auth.two_factor_recovery_hint')} />
                <Button type="submit" variant="primary" size="lg" block loading={busy}>{t('auth.verify')}</Button>
                <a href="/login" className="strong" style={{ textAlign: 'center' }}>{t('auth.back_to_login')}</a>
            </div>
        </form>
    );
}

createPage(TwoFactorPage);
