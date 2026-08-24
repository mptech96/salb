<?php

namespace App\Services\Entitlement;

final class FeatureCatalogService
{
    public function all(): array { return config('sulb_features.features', []); }
    public function limits(): array { return config('sulb_features.limits', []); }
    public function exists(string $code): bool { return array_key_exists($code, $this->all()); }

    public function forUri(string $uri): ?string
    {
        foreach (config('sulb_features.route_prefixes', []) as $prefix => $feature) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) return $feature;
        }
        return null;
    }
}
