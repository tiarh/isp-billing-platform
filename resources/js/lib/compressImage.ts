// Owner 2026-10-09: technician photos are resized on the phone before upload
// (max side ~1600px, JPEG ~0.7) so field uploads stay small on mobile data.
// Any failure (unsupported format, no canvas) falls back to the original file.

const DEFAULT_MAX_SIDE = 1600;
const DEFAULT_QUALITY = 0.7;

type DrawableImage = ImageBitmap | HTMLImageElement;

function loadWithImageElement(file: File): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Gambar tidak dapat dibaca.'));
        };
        image.src = url;
    });
}

async function loadImage(file: File): Promise<DrawableImage> {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            // Fall through to <img>, e.g. older Safari without options support.
        }
    }

    return loadWithImageElement(file);
}

function dimensions(image: DrawableImage): { width: number; height: number } {
    if (image instanceof HTMLImageElement) {
        return { width: image.naturalWidth, height: image.naturalHeight };
    }

    return { width: image.width, height: image.height };
}

export async function compressImage(file: File, maxSide = DEFAULT_MAX_SIDE, quality = DEFAULT_QUALITY): Promise<File> {
    if (typeof document === 'undefined' || !file.type.startsWith('image/')) return file;

    try {
        const image = await loadImage(file);
        const { width, height } = dimensions(image);
        if (!width || !height) return file;

        const scale = Math.min(1, maxSide / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext('2d');
        if (!context) return file;
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        if ('close' in image && typeof image.close === 'function') image.close();

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (!blob) return file;
        // Keep the original when it is already a small JPEG that would not shrink.
        if (scale === 1 && file.type === 'image/jpeg' && blob.size >= file.size) return file;

        const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto';
        return new File([blob], baseName + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
    } catch {
        return file;
    }
}
