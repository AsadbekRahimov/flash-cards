<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Foundation\PackageManifest;

/**
 * The package manifest of the application, aware of the surface (App\Support\Surface).
 *
 * bootstrap/cache/packages.php is written once, by `package:discover` at `composer install` (in the image, for both
 * surfaces alike), so the choice of what to register has to be made when the manifest is READ. RegisterProviders asks
 * providers() and RegisterFacades asks aliases() while the kernel bootstraps; both go through getManifest(), which on the
 * `api` surface leaves out the packages of Surface::API_EXCLUDED_PACKAGES / API_EXCLUDED_PREFIXES. On `full` the answer is
 * the stock manifest, untouched. build() (what `package:discover` calls) is inherited and still writes the complete file.
 *
 * Bound in bootstrap/app.php in place of the stock singleton.
 */
final class SurfacePackageManifest extends PackageManifest
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getManifest(): array
    {
        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = parent::getManifest();

        if (! Surface::isApi()) {
            return $manifest;
        }

        return array_filter(
            $manifest,
            static fn (string $package): bool => ! Surface::excludesPackageOnApi($package),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
