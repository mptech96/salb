<?php

namespace App\Services\Entitlement;

final class FeatureCatalogService
{
    public function all(): array { return config('sulb_features.features', []); }
    public function limits(): array { return config('sulb_features.limits', []); }
    public function exists(string $code): bool { return array_key_exists($code, $this->all()); }

    public function forUri(string $uri): ?string
    {
        $uri = $this->normalizeRouteUri($uri);
        $prefixes = config('sulb_features.route_prefixes', []);
        uksort($prefixes, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($prefixes as $prefix => $feature) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) return $feature;
        }
        return null;
    }

    private function normalizeRouteUri(string $uri): string
    {
        $normalized = trim($uri, '/');

        return str_starts_with($normalized, 'api/')
            ? substr($normalized, 4)
            : $normalized;
    }
}
