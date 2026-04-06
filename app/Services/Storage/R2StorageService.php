<?php

namespace App\Services\Storage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class R2StorageService
{
    public function upload(UploadedFile $file, string $directory, ?string $filename = null): string
    {
        $disk = $this->disk();
        $storedFilename = $filename ?? Str::uuid()->toString().'.'.$this->extensionFor($file);

        $storedPath = $disk->putFileAs(
            trim($directory, '/'),
            $file,
            $storedFilename,
            [
                'visibility' => 'public',
                'ContentType' => $file->getMimeType(),
            ],
        );

        if ($storedPath === false) {
            throw new RuntimeException('Unable to upload file to Cloudflare R2.');
        }

        return $storedPath;
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $disk = $this->disk();

        if ($disk->exists($path)) {
            $deleted = $disk->delete($path);

            if ($deleted === false) {
                throw new RuntimeException('Unable to delete file from Cloudflare R2.');
            }
        }
    }

    public function deleteIfExists(?string $path): void
    {
        $this->delete($path);
    }

    public function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $configuredUrl = trim((string) config('filesystems.disks.r2.url', ''), '/');

        if ($configuredUrl === '') {
            throw new LogicException('R2_PUBLIC_URL is not configured for the r2 filesystem disk.');
        }

        return $configuredUrl.'/'.ltrim($path, '/');
    }

    private function disk(): FilesystemAdapter
    {
        $requiredConfig = [
            'key' => config('filesystems.disks.r2.key'),
            'secret' => config('filesystems.disks.r2.secret'),
            'bucket' => config('filesystems.disks.r2.bucket'),
            'endpoint' => config('filesystems.disks.r2.endpoint'),
        ];

        foreach ($requiredConfig as $key => $value) {
            if (! app()->runningUnitTests() && blank($value)) {
                throw new LogicException(sprintf('R2 filesystem is not configured: missing %s.', $key));
            }
        }

        return Storage::disk('r2');
    }

    private function extensionFor(UploadedFile $file): string
    {
        return strtolower(
            $file->guessExtension()
            ?? $file->getClientOriginalExtension()
            ?? 'bin'
        );
    }
}
