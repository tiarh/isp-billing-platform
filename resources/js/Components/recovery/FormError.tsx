export function FormError({ errors }: { errors: Record<string, string> }) {
    const messages = Object.values(errors);
    if (messages.length === 0) return null;

    return <p className="text-sm text-status-down" role="alert">{messages.join(' ')}</p>;
}
