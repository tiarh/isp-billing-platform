import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from "lucide-react";
import { router } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/Components/ui/select";
import { PaginatedData } from "@/Components/DataTable";

interface DataTablePaginationProps<T> {
    data: PaginatedData<T>;
    filters?: Record<string, any>;
    onPageChange?: (page: number) => void;
    onPageSizeChange?: (pageSize: number) => void;
    pageSizeOptions?: number[];
}

// Matches App\Support\PerPage::ALLOWED. Pages whose backend allows more pass pageSizeOptions.
const DEFAULT_PAGE_SIZE_OPTIONS = [10, 20, 25, 50, 100];

export function DataTablePagination<T>({
    data,
    filters = {},
    onPageChange,
    onPageSizeChange,
    pageSizeOptions = DEFAULT_PAGE_SIZE_OPTIONS,
}: DataTablePaginationProps<T>) {
    // Always include the current page size so the select never renders empty.
    const sizeOptions = Array.from(new Set([...pageSizeOptions, ...(data.per_page ? [Number(data.per_page)] : [])])).sort((a, b) => a - b);

    const handlePageChange = (page: number) => {
        if (onPageChange) {
            onPageChange(page);
        } else {
            router.get(data.path, { ...filters, page: page, limit: data.per_page }, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const handlePageSizeChange = (val: string) => {
        const pageSize = Number(val);
        if (onPageSizeChange) {
            onPageSizeChange(pageSize);
        } else {
            router.get(data.path, { ...filters, limit: val }, {
                preserveState: true,
                preserveScroll: true
            });
        }
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 px-2 pt-4">
            <div className="text-[13px] text-muted-foreground tabular-nums">
                Menampilkan <span className="font-medium text-foreground">{data.from || 0}</span>–<span className="font-medium text-foreground">{data.to || 0}</span> dari <span className="font-medium text-foreground">{data.total}</span> data
            </div>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3 lg:gap-x-8">
                <div className="flex items-center space-x-2">
                    <p className="text-[13px] text-muted-foreground">Baris per halaman</p>
                    <Select
                        value={data.per_page ? String(data.per_page) : "20"}
                        onValueChange={handlePageSizeChange}
                    >
                        <SelectTrigger aria-label="Jumlah baris per halaman" className="h-8 w-[70px]">
                            <SelectValue placeholder="20" />
                        </SelectTrigger>
                        <SelectContent>
                            {sizeOptions.map((pageSize) => (
                                <SelectItem key={pageSize} value={`${pageSize}`}>
                                    {pageSize}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="flex items-center justify-center text-[13px] text-muted-foreground tabular-nums">
                    Hal. {data.current_page} / {data.last_page}
                </div>
                <div className="flex items-center space-x-2">
                    <Button
                        type="button"
                        variant="ghost"
                        className="hidden h-8 w-8 p-0 lg:flex"
                        onClick={() => handlePageChange(1)}
                        disabled={data.current_page === 1}
                    >
                        <span className="sr-only">Halaman pertama</span>
                        <ChevronsLeft className="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-8 w-8 p-0"
                        onClick={() => {
                            if (onPageChange) {
                                handlePageChange(data.current_page - 1);
                                return;
                            }
                            const prevUrl = data.links?.[0]?.url;
                            if (prevUrl) {
                                router.get(prevUrl, { limit: data.per_page }, {
                                    preserveScroll: true,
                                    preserveState: true,
                                });
                            }
                        }}
                        disabled={data.current_page === 1}
                    >
                        <span className="sr-only">Halaman sebelumnya</span>
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-8 w-8 p-0"
                        onClick={() => {
                            if (onPageChange) {
                                handlePageChange(data.current_page + 1);
                                return;
                            }
                            const nextUrl = data.links?.[data.links.length - 1]?.url;
                            if (nextUrl) {
                                router.get(nextUrl, { limit: data.per_page }, {
                                    preserveScroll: true,
                                    preserveState: true,
                                });
                            }
                        }}
                        disabled={data.current_page === data.last_page}
                    >
                        <span className="sr-only">Halaman berikutnya</span>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        className="hidden h-8 w-8 p-0 lg:flex"
                        onClick={() => handlePageChange(data.last_page)}
                        disabled={data.current_page === data.last_page}
                    >
                        <span className="sr-only">Halaman terakhir</span>
                        <ChevronsRight className="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
