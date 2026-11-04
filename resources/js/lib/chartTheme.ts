import type { CSSProperties } from 'react';

/**
 * Recharts' default tooltip is a hard-coded white box whose label inherits the
 * page text color, so in dark mode it rendered light text on white. Spread
 * this into every recharts <Tooltip>.
 */
export const chartTooltipProps: {
    contentStyle: CSSProperties;
    labelStyle: CSSProperties;
    itemStyle: CSSProperties;
    cursor: { fill: string; stroke: string };
} = {
    contentStyle: {
        backgroundColor: 'var(--color-popover)',
        borderColor: 'var(--color-border)',
        color: 'var(--color-popover-foreground)',
        borderRadius: 8,
        fontSize: 12,
    },
    labelStyle: { color: 'var(--color-popover-foreground)', fontWeight: 600 },
    itemStyle: { color: 'var(--color-popover-foreground)' },
    cursor: { fill: 'var(--color-muted)', stroke: 'var(--color-border)' },
};
