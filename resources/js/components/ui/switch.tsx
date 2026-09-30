import * as React from 'react';

import { cn } from '@/lib/utils';

type SwitchProps = Omit<React.ComponentProps<'button'>, 'onChange'> & {
    checked: boolean;
    onCheckedChange?: (checked: boolean) => void;
};

/**
 * An on/off control. A button with the switch role rather than a checkbox, so
 * a screen reader announces it as something that takes effect at once.
 */
function Switch({ checked, onCheckedChange, className, disabled, ...props }: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            data-slot="switch"
            data-state={checked ? 'checked' : 'unchecked'}
            disabled={disabled}
            onClick={() => onCheckedChange?.(!checked)}
            className={cn(
                'inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-transparent transition-colors outline-none',
                'focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary' : 'bg-input',
                className,
            )}
            {...props}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'pointer-events-none block size-4 rounded-full bg-background shadow-sm transition-transform',
                    checked ? 'translate-x-4' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}

export { Switch };
