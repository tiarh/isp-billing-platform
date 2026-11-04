import { useEffect, useState } from 'react';
import { Moon, Sun, Laptop } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { getStoredTheme, setTheme, type Theme } from '@/lib/theme';

export function ThemeToggle() {
    const [theme, setThemeState] = useState<Theme>('system');

    useEffect(() => {
        setThemeState(getStoredTheme());

        const onThemeChange = (event: Event) => {
            const detail = (event as CustomEvent<{ theme: Theme }>).detail;
            if (detail?.theme) {
                setThemeState(detail.theme);
            }
        };

        window.addEventListener('theme-change', onThemeChange);
        return () => window.removeEventListener('theme-change', onThemeChange);
    }, []);

    const updateTheme = (next: Theme) => {
        setTheme(next);
        setThemeState(next);
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative h-8 w-8 rounded-md text-muted-foreground hover:text-foreground">
                    <Sun className="h-[18px] w-[18px] rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0" />
                    <Moon className="absolute h-[18px] w-[18px] rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100" />
                    <span className="sr-only">Toggle theme</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => updateTheme('light')} className={theme === 'light' ? 'bg-muted' : ''}>
                    <Sun className="mr-2 h-4 w-4" />
                    Light
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => updateTheme('dark')} className={theme === 'dark' ? 'bg-muted' : ''}>
                    <Moon className="mr-2 h-4 w-4" />
                    Dark
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => updateTheme('system')} className={theme === 'system' ? 'bg-muted' : ''}>
                    <Laptop className="mr-2 h-4 w-4" />
                    System
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}