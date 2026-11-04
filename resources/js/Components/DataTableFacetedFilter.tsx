import * as React from "react"
import { Check, ChevronDown } from "lucide-react"

import { cn } from "@/lib/utils"
import { Button } from "@/Components/ui/button"
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from "@/Components/ui/command"
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover"

interface DataTableFacetedFilterProps {
    title?: string
    options: {
        label: string
        value: string
        icon?: React.ComponentType<{ className?: string }>
    }[]
    selectedValues: Set<string>
    onSelect: (values: Set<string>) => void
    className?: string
}

export function DataTableFacetedFilter({
    title,
    options,
    selectedValues,
    onSelect,
    className,
}: DataTableFacetedFilterProps) {
    const selectedCount = selectedValues?.size ?? 0
    const selectedLabel = selectedCount === 1
        ? options.find((option) => selectedValues.has(option.value))?.label ?? title
        : `${selectedCount} dipilih`

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={cn(
                        "h-9 shrink-0 gap-1.5 border-input bg-card px-3 text-foreground",
                        selectedCount > 0 && "border-transparent bg-primary-soft text-primary-soft-foreground hover:bg-primary-soft",
                        className
                    )}
                >
                    {selectedCount > 0 ? selectedLabel : title}
                    <ChevronDown aria-hidden="true" className="h-3.5 w-3.5 opacity-60" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[200px] p-0" align="start">
                <Command>
                    <CommandInput placeholder={title} />
                    <CommandList>
                        <CommandEmpty>Tidak ada hasil.</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => {
                                const isSelected = selectedValues.has(option.value)
                                return (
                                    <CommandItem
                                        key={option.value}
                                        onSelect={() => {
                                            const newSelectedValues = new Set(selectedValues)
                                            if (isSelected) {
                                                newSelectedValues.delete(option.value)
                                            } else {
                                                newSelectedValues.add(option.value)
                                            }
                                            onSelect(newSelectedValues)
                                        }}
                                    >
                                        <div
                                            className={cn(
                                                "mr-2 flex h-4 w-4 items-center justify-center rounded-sm border border-primary",
                                                isSelected
                                                    ? "bg-primary text-primary-foreground"
                                                    : "opacity-50 [&_svg]:invisible"
                                            )}
                                        >
                                            <Check className={cn("h-4 w-4")} />
                                        </div>
                                        {option.icon && (
                                            <option.icon className="mr-2 h-4 w-4 text-muted-foreground" />
                                        )}
                                        <span>{option.label}</span>
                                        {/* Count functionality removed for generic usage without facets count prop */}
                                    </CommandItem>
                                )
                            })}
                        </CommandGroup>
                        {selectedValues.size > 0 && (
                            <>
                                <CommandSeparator />
                                <CommandGroup>
                                    <CommandItem
                                        onSelect={() => onSelect(new Set())}
                                        className="justify-center text-center"
                                    >
                                        Hapus filter
                                    </CommandItem>
                                </CommandGroup>
                            </>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    )
}
