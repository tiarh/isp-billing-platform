import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex h-[22px] max-w-full items-center gap-1 whitespace-nowrap rounded-[var(--radius-pill)] border px-2.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground shadow hover:bg-primary/80",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80",
        destructive:
          "border-transparent bg-destructive text-destructive-foreground shadow hover:bg-destructive/80",
        outline: "text-foreground",
        // Pastel status pairs (no border): fill + AA-checked text colour.
        up: "border-transparent bg-status-up-bg text-status-up",
        down: "border-transparent bg-status-down-bg text-status-down",
        warning: "border-transparent bg-status-degraded-bg text-status-degraded",
        info: "border-transparent bg-status-info-bg text-status-info",
        neutral: "border-transparent bg-muted text-muted-foreground",
        accent: "border-transparent bg-primary-soft text-primary-soft-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

export interface BadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <div className={cn(badgeVariants({ variant }), className)} {...props} />
  )
}

export { Badge, badgeVariants }
