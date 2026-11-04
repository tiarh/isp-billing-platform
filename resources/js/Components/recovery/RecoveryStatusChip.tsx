import { StatusBadge } from '@/Components/StatusBadge';
import { RecoveryCase, STATUS_TONES } from './types';

export function RecoveryStatusChip({ item }: { item: RecoveryCase }) {
    const label = !item.substatus || item.substatus === item.status_group ? item.status_group : `${item.status_group} · ${item.substatus}`;

    return <StatusBadge label={label} tone={STATUS_TONES[item.status] || 'neutral'} />;
}
