import clsx from 'clsx';
import { useId, useState, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { t } from '@/lib/i18n';
import { Icon } from './Icon';

/** Label + control + hint/error. Every input in the app is wrapped in a Field. */
export function Field({ label, required, optional, hint, error, className, children, htmlFor }: {
    label?: ReactNode; required?: boolean; optional?: boolean; hint?: ReactNode; error?: string | null;
    className?: string; children: ReactNode; htmlFor?: string;
}) {
    return (
        <div className={clsx('field', className)}>
            {label && (
                <label className="field-label" htmlFor={htmlFor}>
                    {label}
                    {required && <span className="req">*</span>}
                    {optional && <span className="opt">({t('ui.optional')})</span>}
                </label>
            )}
            {children}
            {error ? <div className="field-error" role="alert">{error}</div> : hint ? <div className="field-hint">{hint}</div> : null}
        </div>
    );
}

type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> & {
    label?: ReactNode; hint?: ReactNode; error?: string | null; icon?: string; suffix?: ReactNode; optional?: boolean;
    fieldClass?: string; size?: 'sm' | 'md';
};

export function Input({ label, hint, error, icon, suffix, optional, required, fieldClass, size = 'md', className, id, type, ...rest }: InputProps) {
    const auto = useId();
    const inputId = id ?? auto;
    const [show, setShow] = useState(false);
    const isPassword = type === 'password';
    const control = (
        <div className={clsx('control', size === 'sm' && 'control-sm', error && 'invalid', rest.disabled && 'disabled', className)}>
            {icon && <Icon name={icon} size={17} className="control-icon" />}
            <input id={inputId} type={isPassword && show ? 'text' : type} required={required} aria-invalid={!!error} {...rest} />
            {suffix && <span className="control-suffix">{suffix}</span>}
            {isPassword && (
                <button type="button" className="control-action" onClick={() => setShow((s) => !s)} aria-label={show ? t('ui.hide_password') : t('ui.show_password')} title={show ? t('ui.hide_password') : t('ui.show_password')}>
                    <Icon name={show ? 'eye-off' : 'eye'} size={17} />
                </button>
            )}
        </div>
    );
    if (!label && !hint && !error) return control;
    return <Field label={label} hint={hint} error={error} required={required} optional={optional} className={fieldClass} htmlFor={inputId}>{control}</Field>;
}

export interface Option { value: string | number; label: string; disabled?: boolean }

type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> & {
    label?: ReactNode; hint?: ReactNode; error?: string | null; options: Option[]; placeholder?: string; optional?: boolean;
    fieldClass?: string; size?: 'sm' | 'md'; icon?: string;
};

export function Select({ label, hint, error, options, placeholder, optional, required, fieldClass, size = 'md', className, id, icon, ...rest }: SelectProps) {
    const auto = useId();
    const selectId = id ?? auto;
    const control = (
        <div className={clsx('control', size === 'sm' && 'control-sm', error && 'invalid', rest.disabled && 'disabled', className)}>
            {icon && <Icon name={icon} size={17} className="control-icon" />}
            <select id={selectId} required={required} aria-invalid={!!error} {...rest}>
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((o) => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>)}
            </select>
            <Icon name="chevron-down" size={16} className="control-chevron" />
        </div>
    );
    if (!label && !hint && !error) return control;
    return <Field label={label} hint={hint} error={error} required={required} optional={optional} className={fieldClass} htmlFor={selectId}>{control}</Field>;
}

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label?: ReactNode; hint?: ReactNode; error?: string | null; optional?: boolean; fieldClass?: string };

export function Textarea({ label, hint, error, optional, required, fieldClass, id, ...rest }: TextareaProps) {
    const auto = useId();
    const tid = id ?? auto;
    return (
        <Field label={label} hint={hint} error={error} required={required} optional={optional} className={fieldClass} htmlFor={tid}>
            <div className={clsx('control textarea', error && 'invalid')}>
                <textarea id={tid} required={required} {...rest} />
            </div>
        </Field>
    );
}

export function Checkbox({ label, ...rest }: InputHTMLAttributes<HTMLInputElement> & { label?: ReactNode }) {
    return (
        <label className="check">
            <input type="checkbox" {...rest} />
            {label && <span>{label}</span>}
        </label>
    );
}

export function Toggle({ label, checked, onChange, disabled }: { label?: ReactNode; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
    return (
        <label className="toggle">
            <input type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            <span className="track" />
            {label && <span>{label}</span>}
        </label>
    );
}

/** Titled block of related fields laid out on the 12-column grid. */
export function FormSection({ title, description, actions, children }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; children: ReactNode }) {
    return (
        <section className="form-section">
            <header>
                <div>
                    <h3>{title}</h3>
                    {description && <p>{description}</p>}
                </div>
                {actions}
            </header>
            <div className="form-body">
                <div className="form-grid">{children}</div>
            </div>
        </section>
    );
}
