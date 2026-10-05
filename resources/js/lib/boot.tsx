import { Component, StrictMode, type ComponentType, type ErrorInfo, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { AppLayout } from '@/components/shell/AppLayout';
import { AuthLayout } from '@/components/shell/AuthLayout';
import { EmptyState, ToastProvider, toast } from '@/components/ui';
import { installErrorReporting, reportError } from './errors';
import { t } from './i18n';
import { payload } from './page';

class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };
    static getDerivedStateFromError() {
        return { failed: true };
    }
    componentDidCatch(error: Error, info: ErrorInfo) {
        reportError(error, info.componentStack ?? undefined);
    }
    render() {
        return this.state.failed
            ? <div className="content"><EmptyState icon="circle-alert" title={t('errors.title_500')} text={t('errors.500')} /></div>
            : this.props.children;
    }
}

/**
 * Entry point of every page:
 *   createPage<Props>((props) => <MyPage {...props} />)
 */
export function createPage<P>(PageComponent: ComponentType<P>): void {
    installErrorReporting();
    const data = payload<P>();
    document.body.dataset.page = data.page;
    const root = createRoot(document.getElementById('root')!);

    const content = (
        <ErrorBoundary>
            <PageComponent {...(data.props as P & object)} />
        </ErrorBoundary>
    );

    root.render(
        <StrictMode>
            <ToastProvider>
                {data.layout === 'app' ? <AppLayout shell={data.shell}>{content}</AppLayout>
                    : data.layout === 'auth' ? <AuthLayout shell={data.shell}>{content}</AuthLayout>
                        : content}
            </ToastProvider>
        </StrictMode>,
    );

    queueMicrotask(() => {
        if (data.flash.success) toast.success(data.flash.success);
        if (data.flash.notice) toast.info(data.flash.notice);
        if (data.flash.error) toast.error(data.flash.error);
    });
}
