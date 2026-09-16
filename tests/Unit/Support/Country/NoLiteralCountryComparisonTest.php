<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Country;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Invariant 73: code never branches on a literal country code outside
 * App\Support\Country\.
 */
class NoLiteralCountryComparisonTest extends TestCase
{
    #[Test]
    public function app_outside_country_namespace_has_no_literal_country_comparison(): void
    {
        $root = dirname(__DIR__, 4).'/app';
        $allowed = $root.'/Support/Country';
        $pattern = "/(?:===|!==|==|!=)\\s*['\"](?:ES|FR|GB)['\"]/";
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_starts_with($path, $allowed)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Literal country-code comparisons must live in App\\Support\\Country\\:\n".implode("\n", $offenders),
        );
    }
}
