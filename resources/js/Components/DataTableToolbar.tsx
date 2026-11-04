import { Search, X } from "lucide-react";
import { Button } from "@/Components/ui/button";
import { Input } from "@/Components/ui/input";
import { DataTableFacetedFilter } from "@/Components/DataTableFacetedFilter";
import { FilterConfig } from "@/Components/DataTable";
import { ReactNode } from "react";

interface DataTableToolbarProps {
    search: string;
    onSearchChange: (value: string) => void;
    searchPlaceholder?: string;
    activeFilters: Record<string, string>; // Kept as string for compatibility, but we might want to support arrays
    filterConfigs: FilterConfig[];
    onFilterChange: (key: string, value: string) => void;
    onReset: () => void;
    actions?: ReactNode;
    summary?: ReactNode;
}

export function DataTableToolbar({
    search,
    onSearchChange,
    searchPlaceholder = "Cari...",
    activeFilters,
    filterConfigs,
    onFilterChange,
    onReset,
    actions,
    summary,
}: DataTableToolbarProps) {
    const isFiltered = search.length > 0 || Object.values(activeFilters).some(v => v !== 'all');

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative w-full sm:w-[320px]">
                <Input
                    type="search"
                    aria-label={searchPlaceholder}
                    placeholder={searchPlaceholder}
                    value={search}
                    onChange={(e) => onSearchChange(e.target.value)}
                    className="h-9 w-full bg-card border-input focus-visible:ring-ring pl-9"
                />
                <Search aria-hidden="true" className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            </div>

            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                {filterConfigs.map(config => {
                    // Adapt the single value "all" | string to Set<string> for FacetedFilter
                    const selectedValue = activeFilters[config.key] || 'all';
                    const selectedSet = new Set(selectedValue !== 'all' ? selectedValue.split(',') : []);

                    return (
                        <DataTableFacetedFilter
                            key={config.key}
                            title={config.placeholder}
                            options={config.options}
                            selectedValues={selectedSet}
                            className={config.hideOnMobile ? 'hidden sm:inline-flex' : undefined}
                            onSelect={(values) => {
                                // Convert Set to comma separated string or 'all'
                                const newValues = Array.from(values);
                                const newVal = newValues.length > 0 ? newValues.join(',') : 'all';
                                onFilterChange(config.key, newVal);
                            }}
                        />
                    );
                })}

                {isFiltered && (
                    <Button
                        variant="ghost"
                        onClick={onReset}
                        className="h-9 shrink-0 px-2 text-muted-foreground hover:text-destructive lg:px-3"
                    >
                        Reset
                        <X className="ml-2 h-4 w-4" />
                    </Button>
                )}
            </div>

            {(summary || actions) && (
                <div className="flex items-center justify-between gap-3 sm:ml-auto sm:justify-end">
                    {summary && (
                        <span className="whitespace-nowrap text-[13px] tabular-nums text-muted-foreground">{summary}</span>
                    )}
                    {actions && (
                        <div className="flex items-center gap-2">
                            {actions}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
