import type { Point } from './types';

interface SparklineProps {
    points: Point[];
    /** Any CSS colour, normally a token such as var(--chart-2). */
    color?: string;
    width?: number;
    height?: number;
    label: string;
}

/** Tiny inline-SVG trend line; gaps (null values) break the line. */
export function Sparkline({ points, color = 'var(--chart-2)', width = 96, height = 24, label }: SparklineProps) {
    const values = points.map((point) => point[1]).filter((value): value is number => value !== null && Number.isFinite(value));
    if (values.length < 2) {
        return <span className="text-xs text-muted-foreground" aria-label={`${label}: belum ada data`}>—</span>;
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const pad = 2;
    const stepX = (width - pad * 2) / (points.length - 1);

    let path = '';
    let pen = false;
    points.forEach((point, index) => {
        const value = point[1];
        if (value === null || !Number.isFinite(value)) {
            pen = false;

            return;
        }
        const x = pad + index * stepX;
        const y = height - pad - ((value - min) / span) * (height - pad * 2);
        path += `${pen ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)} `;
        pen = true;
    });

    return (
        <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} role="img" aria-label={label} className="block overflow-visible">
            <path d={path.trim()} fill="none" stroke={color} strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}
