import { useEffect, useState } from 'react';
import { MapContainer, TileLayer, Marker, useMapEvents } from 'react-leaflet';
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

// Fix for default marker icon in React-Leaflet/Vite
import icon from 'leaflet/dist/images/marker-icon.png';
import iconShadow from 'leaflet/dist/images/marker-shadow.png';

let DefaultIcon = L.icon({
    iconUrl: icon,
    shadowUrl: iconShadow,
    iconSize: [25, 41],
    iconAnchor: [12, 41]
});

L.Marker.prototype.options.icon = DefaultIcon;

interface MapPickerProps {
    position: [number, number];
    onLocationSelect?: (lat: number, lng: number) => void;
}

// Component to handle map clicks
function LocationMarker({ position, onLocationSelect }: MapPickerProps) {
    const map = useMapEvents({
        click(e) {
            if (onLocationSelect) {
                onLocationSelect(e.latlng.lat, e.latlng.lng);
                map.flyTo(e.latlng, map.getZoom());
            }
        },
    });

    return position ? <Marker position={position} /> : null;
}

export default function MapPicker({
    initialLat = -6.200000,
    initialLong = 106.816666,
    onLocationSelect
}: {
    initialLat?: number,
    initialLong?: number,
    onLocationSelect?: (lat: number, lng: number) => void
}) {
    const [position, setPosition] = useState<[number, number]>([initialLat, initialLong]);

    // Update internal state if props change (e.g., from manual input)
    useEffect(() => {
        if (initialLat && initialLong) {
            setPosition([initialLat, initialLong]);
        }
    }, [initialLat, initialLong]);

    const handleSelect = (lat: number, lng: number) => {
        setPosition([lat, lng]);
        if (onLocationSelect) {
            onLocationSelect(lat, lng);
        }
    };

    return (
        <div className="h-64 w-full rounded-md overflow-hidden border border-border z-0 relative">
            <MapContainer
                center={position}
                zoom={13}
                scrollWheelZoom={false}
                style={{ height: '100%', width: '100%', zIndex: 0 }}
            >
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />
                <LocationMarker position={position} onLocationSelect={handleSelect} />
            </MapContainer>
        </div>
    );
}
