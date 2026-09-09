import { ChevronsUpDown, Store } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckedItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentLocation } from '@/hooks/use-locations';
import { useLocationStore } from '@/stores/location';

/**
 * Which store front the screens are showing. An organization with one store
 * front has nothing to switch between, so the control stays out of the way.
 */
export function LocationSwitcher() {
    const { location, locations, isPending } = useCurrentLocation();
    const setCurrent = useLocationStore((state) => state.setCurrent);

    if (isPending || locations.length === 0) {
        return null;
    }

    if (locations.length === 1) {
        return (
            <span className="flex items-center gap-2 text-sm text-muted-foreground">
                <Store className="size-4 shrink-0" aria-hidden="true" />
                <span className="max-w-[12rem] truncate">{locations[0].name}</span>
            </span>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" className="max-w-[16rem] justify-between gap-2">
                    <Store className="size-4 shrink-0" aria-hidden="true" />
                    <span className="truncate">{location?.name ?? '店舗を選択'}</span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-60" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-64">
                <DropdownMenuLabel>店舗を切り替え</DropdownMenuLabel>
                {locations.map((entry) => (
                    <DropdownMenuCheckedItem
                        key={entry.id}
                        checked={entry.id === location?.id}
                        onSelect={() => setCurrent(entry.id)}
                    >
                        <span className="truncate">{entry.name}</span>
                    </DropdownMenuCheckedItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
