import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit max-w-[min(100%,12rem)] min-w-0 whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        destructive:
          "border-transparent bg-destructive-soft text-destructive-soft-foreground [a&]:hover:bg-destructive-soft/80 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        success:
          "border-transparent bg-success-soft text-success-soft-foreground [a&]:hover:bg-success-soft/80",
        warning:
          "border-transparent bg-warning-soft text-warning-soft-foreground [a&]:hover:bg-warning-soft/80",
        info:
          "border-transparent bg-info-soft text-info-soft-foreground [a&]:hover:bg-info-soft/80",
        outline:
          "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  children,
  title,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"
  // Plain-text badges (names) end in an ellipsis when too long, with the full text on hover.
  const text = typeof children === "string" ? children : undefined

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      title={title ?? text}
      {...props}
    >
      {text !== undefined && !asChild ? <span className="min-w-0 truncate [unicode-bidi:plaintext]">{text}</span> : children}
    </Comp>
  )
}

export { Badge, badgeVariants }
