<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\Auth\SubjectSite;
use App\Support\Auth\UnresolvableSubjectSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectSiteCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_morph_mapped_model_is_resolvable(): void
    {
        $map = Relation::morphMap();
        $this->assertNotEmpty($map, 'Morph map must be registered');

        foreach ($map as $alias => $class) {
            $this->assertTrue(
                class_exists($class),
                "Morph alias {$alias} points at missing class {$class}",
            );

            /** @var Model $instance */
            $instance = new $class;

            try {
                // null is a valid company-level resolution; only UnresolvableSubjectSite is a gap.
                SubjectSite::for($instance);
                $this->addToAssertionCount(1);
            } catch (UnresolvableSubjectSite $e) {
                $this->fail("Morph-mapped model {$class} (alias {$alias}) is not in SubjectSite: {$e->getMessage()}");
            } catch (\Throwable) {
                // Missing FKs / empty models may throw inside an arm; the arm still exists.
                $this->addToAssertionCount(1);
            }
        }
    }
}
