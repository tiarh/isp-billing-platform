import { useState, useEffect } from 'react';
import axios from 'axios';
import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/Components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Save, FileText, DollarSign, Check, ChevronsUpDown, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { currentMonthWib } from '@/lib/dateTime';
import { formatIdr } from '@/lib/format';
import { FormEventHandler } from 'react';
import { requiredId, requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { z } from 'zod';

interface Package {
    id: number;
    name: string;
    price: number;
}

interface Customer {
    id: number;
    name: string;
    code: string | null;
    package_id: number;
    due_day: number | null;
    area_id: number | null;
    package: Package;
}

interface Area {
    id: number;
    name: string;
    code: string;
}

interface Props {
    /** I2: no longer preloaded (always empty); kept for payload compatibility. */
    customers?: Customer[];
    areas: Area[];
}

const SEARCH_LIMIT = 50;

const invoiceCreateSchema = z.object({
    customer_id: requiredId('Customer'),
    period: requiredString('Billing period').regex(/^\d{4}-\d{2}$/, 'Billing period is invalid.'),
    amount: requiredNumber('Amount', 0),
    due_date: requiredString('Due date').regex(/^\d{4}-\d{2}-\d{2}$/, 'Due date is invalid.'),
});

export default function Create({ areas }: Props) {
    const currentPeriod = currentMonthWib();

    const form = useForm({
        customer_id: '',
        period: currentPeriod,
        amount: '',
        due_date: '',
    });
    const { data, setData, post, processing, errors } = form;

    const [open, setOpen] = useState(false);
    const [selectedAreaId, setSelectedAreaId] = useState<string>('all');
    const [searchTerm, setSearchTerm] = useState('');
    // I2: server-side search (debounced, max 50 rows) instead of rendering every
    // billable customer as a CommandItem.
    const [options, setOptions] = useState<Customer[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState(false);
    const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);

    useEffect(() => {
        if (!open) return;

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setSearching(true);
            setSearchError(false);
            try {
                const res = await axios.get<Customer[]>(route('invoices.customer-options'), {
                    params: {
                        q: searchTerm.trim() || undefined,
                        area_id: selectedAreaId !== 'all' ? selectedAreaId : undefined,
                    },
                    signal: controller.signal,
                });
                setOptions(Array.isArray(res.data) ? res.data : []);
            } catch (error) {
                if (!axios.isCancel(error)) {
                    setOptions([]);
                    setSearchError(true);
                }
            } finally {
                if (!controller.signal.aborted) setSearching(false);
            }
        }, 300);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, searchTerm, selectedAreaId]);

    // Auto-fill amount and due_date when customer changes
    useEffect(() => {
        if (!data.customer_id) return;

        const customer = selectedCustomer;
        if (!customer || customer.id !== Number(data.customer_id)) return;

        // Auto-fill amount from customer's package price
        const price = customer.package?.price ?? 0;

        // Auto-calculate due date from period + customer's due_day
        const dueDay = customer.due_day ?? 20;
        const [year, month] = data.period.split('-').map(Number);
        const daysInMonth = new Date(year, month, 0).getDate();
        const day = Math.min(dueDay, daysInMonth);
        const dueDate = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

        setData(prev => ({
            ...prev,
            amount: String(price),
            due_date: dueDate,
        }));
    }, [data.customer_id, data.period, selectedCustomer]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!validateForm(invoiceCreateSchema, data, form)) return;
        post(route('invoices.store'));
    };


    return (
        <EnterpriseFormLayout
            title="Create Invoice"
            pageTitle="Create Invoice"
            subtitle="Buat tagihan manual untuk pelanggan"
            icon={FileText}
            backHref={route('invoices.index')}
            breadcrumbs={[
                { label: 'Invoices', href: route('invoices.index') },
                { label: 'Create' },
            ]}
            maxWidth="5xl"
        >
            <form onSubmit={submit} className="space-y-8">
                {Object.keys(errors).length > 0 && (
                    <div className="bg-destructive/15 text-destructive p-4 rounded-md border border-destructive/20">
                        <p className="font-semibold">Please fix the following errors:</p>
                        <ul className="list-disc list-inside text-sm mt-1">
                            {Object.entries(errors).map(([field, msg]) => (
                                <li key={field}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="mx-auto max-w-2xl space-y-6">
                    {/* Customer & Period */}
                    <Card className="border-border bg-card/50">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <div className="p-2 bg-primary/10 rounded-lg text-primary">
                                    <FileText className="h-5 w-5" />
                                </div>
                                <div>
                                    <CardTitle>Invoice Details</CardTitle>
                                    <CardDescription>Select a customer and billing period</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">

                            {/* Area Filter */}
                            <div className="grid gap-2">
                                <Label>Filter by Area</Label>
                                <Select
                                    value={selectedAreaId}
                                    onValueChange={(value) => {
                                        setSelectedAreaId(value);
                                        setOptions([]);
                                    }}
                                >
                                    <SelectTrigger className="bg-background/50">
                                        <SelectValue placeholder="All Areas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Areas</SelectItem>
                                        {areas.map((area) => (
                                            <SelectItem key={area.id} value={String(area.id)}>
                                                {area.name} ({area.code})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Customer Combobox */}
                            <div className="grid gap-2">
                                <Label htmlFor="customer_id">Customer <span className="text-destructive">*</span></Label>
                                <Popover open={open} onOpenChange={setOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={open}
                                            className="w-full justify-between bg-background/50 font-normal"
                                        >
                                            {selectedCustomer
                                                ? `${selectedCustomer.name} (${selectedCustomer.code || 'No Code'})`
                                                : "Select customer..."}
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-[--radix-popover-trigger-width] p-0">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Cari nama, kode, atau PPPoE..."
                                                value={searchTerm}
                                                onValueChange={setSearchTerm}
                                            />
                                            <CommandList aria-busy={searching}>
                                                {searching && (
                                                    <div role="status" className="flex items-center gap-2 px-3 py-2 text-xs text-muted-foreground">
                                                        <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                                                        Mencari pelanggan...
                                                    </div>
                                                )}
                                                {!searching && (
                                                    <CommandEmpty>
                                                        {searchError ? 'Gagal memuat pelanggan. Coba ketik ulang.' : 'Pelanggan tidak ditemukan.'}
                                                    </CommandEmpty>
                                                )}
                                                <CommandGroup>
                                                    {options.map((customer) => (
                                                        <CommandItem
                                                            key={customer.id}
                                                            value={String(customer.id)}
                                                            onSelect={() => {
                                                                setSelectedCustomer(customer);
                                                                setData('customer_id', String(customer.id));
                                                                setOpen(false);
                                                            }}
                                                        >
                                                            <Check
                                                                className={cn(
                                                                    "mr-2 h-4 w-4",
                                                                    Number(data.customer_id) === customer.id ? "opacity-100" : "opacity-0"
                                                                )}
                                                            />
                                                            <div className="flex flex-col">
                                                                <span className="font-medium">{customer.name}</span>
                                                                <span className="text-xs text-muted-foreground">
                                                                    {customer.code} • {customer.package?.name}
                                                                </span>
                                                            </div>
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                                {errors.customer_id && <p className="text-sm text-destructive">{errors.customer_id}</p>}
                                <p className="text-xs text-muted-foreground text-right">
                                    {options.length >= SEARCH_LIMIT
                                        ? `Menampilkan ${SEARCH_LIMIT} hasil teratas — ketik nama/kode untuk mempersempit`
                                        : `${options.length} pelanggan aktif/isolir cocok`}
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="period">Billing Period <span className="text-destructive">*</span></Label>
                                <Input
                                    id="period"
                                    type="month"
                                    value={data.period}
                                    onChange={(e) => setData('period', e.target.value)}
                                    className={errors.period ? 'border-destructive' : ''}
                                    required
                                />
                                {errors.period && <p className="text-sm text-destructive">{errors.period}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Amount & Due Date */}
                    <Card className="border-border bg-card/50">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <div className="p-2 bg-status-up-bg rounded-lg text-status-up">
                                    <DollarSign className="h-5 w-5" />
                                </div>
                                <div>
                                    <CardTitle>Billing</CardTitle>
                                    <CardDescription>Amount and due date (auto-filled from customer package)</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="amount">Amount (IDR) <span className="text-destructive">*</span></Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        placeholder="0"
                                        className={`font-mono ${errors.amount ? 'border-destructive' : ''}`}
                                        required
                                    />
                                    {errors.amount && <p className="text-sm text-destructive">{errors.amount}</p>}
                                    {selectedCustomer && (
                                        <p className="text-xs text-muted-foreground">
                                            Package: {selectedCustomer.package?.name} — {formatIdr(selectedCustomer.package?.price ?? 0)}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="due_date">Due Date <span className="text-destructive">*</span></Label>
                                    <Input
                                        id="due_date"
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) => setData('due_date', e.target.value)}
                                        className={errors.due_date ? 'border-destructive' : ''}
                                        required
                                    />
                                    {errors.due_date && <p className="text-sm text-destructive">{errors.due_date}</p>}
                                    {selectedCustomer?.due_day && (
                                        <p className="text-xs text-muted-foreground">
                                            Customer's due day: {selectedCustomer.due_day}th of each month
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end gap-4">
                        <Link href={route('invoices.index')}>
                            <Button variant="outline" type="button">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing} className="min-w-[150px]">
                            {processing ? 'Creating...' : 'Create Invoice'}
                            {!processing && <Save className="ml-2 h-4 w-4" />}
                        </Button>
                    </div>
                </div>
            </form>
        </EnterpriseFormLayout>
    );
}
