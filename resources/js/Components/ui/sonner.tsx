import { Toaster as Sonner } from "sonner"
import { useIsDark } from "@/hooks/useIsDark"

type ToasterProps = React.ComponentProps<typeof Sonner>

const Toaster = ({ ...props }: ToasterProps) => {
    // Follow the app theme (.dark on <html>), not the OS: the old system theme kept
    // light toasts when Dark was picked on a light-OS machine, and vice versa.
    const isDark = useIsDark()

    return (
        <Sonner
            theme={isDark ? "dark" : "light"}
            className="toaster group"
            toastOptions={{
                classNames: {
                    toast:
                        "group toast group-[.toaster]:bg-card group-[.toaster]:text-card-foreground group-[.toaster]:border-border group-[.toaster]:rounded-lg group-[.toaster]:shadow-[var(--shadow-card)]",
                    description: "group-[.toast]:text-muted-foreground",
                    actionButton:
                        "group-[.toast]:bg-primary group-[.toast]:text-primary-foreground",
                    cancelButton:
                        "group-[.toast]:bg-muted group-[.toast]:text-muted-foreground",
                },
            }}
            {...props}
        />
    )
}

export { Toaster }
