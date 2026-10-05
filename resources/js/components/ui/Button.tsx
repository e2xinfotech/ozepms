import clsx from 'clsx';
import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react';
import { Icon } from './Icon';

type Variant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'danger-soft';
type Size = 'sm' | 'md' | 'lg';

interface CommonProps {
    variant?: Variant;
    size?: Size;
    icon?: string;
    iconRight?: string;
    loading?: boolean;
    block?: boolean;
    children?: ReactNode;
}

function classes(variant: Variant, size: Size, block?: boolean, iconOnly?: boolean, extra?: string) {
    return clsx('btn', `btn-${variant}`, size !== 'md' && `btn-${size}`, block && 'btn-block', iconOnly && 'btn-icon', extra);
}

export function Button({ variant = 'secondary', size = 'md', icon, iconRight, loading, block, children, className, disabled, type = 'button', ...rest }: CommonProps & ButtonHTMLAttributes<HTMLButtonElement>) {
    const iconSize = size === 'sm' ? 15 : 18;
    return (
        <button type={type} className={classes(variant, size, block, !children, className)} disabled={disabled || loading} {...rest}>
            {loading ? <Icon name="loader" size={iconSize} className="spin" /> : icon && <Icon name={icon} size={iconSize} />}
            {children}
            {iconRight && <Icon name={iconRight} size={iconSize} />}
        </button>
    );
}

export function LinkButton({ variant = 'secondary', size = 'md', icon, iconRight, block, children, className, ...rest }: CommonProps & AnchorHTMLAttributes<HTMLAnchorElement>) {
    const iconSize = size === 'sm' ? 15 : 18;
    return (
        <a className={classes(variant, size, block, !children, className)} {...rest}>
            {icon && <Icon name={icon} size={iconSize} />}
            {children}
            {iconRight && <Icon name={iconRight} size={iconSize} />}
        </a>
    );
}
