import { Component, ReactNode } from "react";

interface EBProps { children: ReactNode; }
interface EBState { hasError: boolean; error: Error | null; }

export class OltErrorBoundary extends Component<EBProps, EBState> {
    constructor(props: EBProps) {
        super(props);
        this.state = { hasError: false, error: null };
    }
    static getDerivedStateFromError(error: Error) {
        return { hasError: true, error };
    }
    render() {
        if (this.state.hasError) {
            return (
                <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-6">
                    <h2 className="mb-2 text-lg font-bold text-destructive">Render Error</h2>
                    <pre className="overflow-auto rounded bg-muted p-4 text-sm text-foreground">{this.state.error?.message}</pre>
                    <pre className="mt-2 max-h-64 overflow-auto rounded bg-muted p-4 text-xs text-foreground">{this.state.error?.stack}</pre>
                </div>
            );
        }
        return this.props.children;
    }
}
