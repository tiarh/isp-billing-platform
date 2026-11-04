import { formatIdr } from '@/lib/format';

interface MoneyTextProps {
    amount: number | string | null | undefined;
    className?: string;
}

export { formatIdr } from '@/lib/format';

export function MoneyText({ amount, className }: MoneyTextProps) {
    return <span className={className}>{formatIdr(amount)}</span>;
}
