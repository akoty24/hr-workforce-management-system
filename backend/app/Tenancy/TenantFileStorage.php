<?php

namespace App\Tenancy;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * File storage confined to the current tenant ("tenants/{id}/...") on the
 * configured disk (FILESYSTEM_DISK: local, s3, any S3-compatible store).
 *
 * Paths are always relative to the tenant's folder; absolute paths, "." /
 * ".." segments, empty segments and NUL bytes are rejected so a caller can
 * never escape into another tenant's files.
 */
final class TenantFileStorage
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FilesystemFactory $filesystems,
    ) {}

    public function path(string $path): string
    {
        return 'tenants/'.$this->context->id().'/'.$this->normalize($path);
    }

    public function put(string $path, mixed $contents, array $options = []): bool
    {
        return (bool) $this->disk()->put($this->path($path), $contents, $options);
    }

    public function get(string $path): ?string
    {
        return $this->disk()->get($this->path($path));
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($this->path($path));
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($this->path($path));
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk();
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('/^[a-zA-Z]:/', $path)) {
            throw new InvalidArgumentException('Tenant file paths must be non-empty and relative.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Tenant file paths may not contain empty, "." or ".." segments.');
            }
        }

        return $path;
    }
}
