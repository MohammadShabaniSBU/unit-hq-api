<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class EvalFixtureLoader
{
    /**
     * @return list<EvalFixture>
     */
    public function load(string $root, ?string $agent = null, ?string $filter = null): array
    {
        $agents = $agent !== null ? [$agent] : ['support', 'sales'];
        $fixtures = [];

        foreach ($agents as $key) {
            $dir = $root.DIRECTORY_SEPARATOR.$key;
            if (! is_dir($dir)) {
                continue;
            }

            $files = glob($dir.DIRECTORY_SEPARATOR.'*.yaml') ?: [];
            sort($files);
            foreach ($files as $file) {
                $fixture = $this->parseFile($file, $key);
                if ($filter !== null && $filter !== '' && ! $this->matchesFilter($fixture, $filter)) {
                    continue;
                }
                $fixtures[] = $fixture;
            }
        }

        return $fixtures;
    }

    public function parseFile(string $path, ?string $expectedAgent = null): EvalFixture
    {
        $data = Yaml::parseFile($path);
        if (! is_array($data)) {
            throw new RuntimeException("Fixture [{$path}] is not a YAML mapping.");
        }

        $id = (string) ($data['id'] ?? '');
        $agent = (string) ($data['agent'] ?? $expectedAgent ?? '');
        if ($id === '' || $agent === '') {
            throw new RuntimeException("Fixture [{$path}] needs id and agent.");
        }

        $turns = $data['turns'] ?? [];
        if (! is_array($turns) || $turns === []) {
            throw new RuntimeException("Fixture [{$id}] needs at least one turn.");
        }

        $tags = [];
        foreach ($data['tags'] ?? [] as $tag) {
            $tags[] = (string) $tag;
        }

        $liveOnly = (bool) ($data['live_only'] ?? false) || in_array('live_only', $tags, true);

        $writePolicies = [];
        $rawPolicies = $data['write_policies'] ?? [];
        if (is_array($rawPolicies)) {
            foreach ($rawPolicies as $toolKey => $mode) {
                $writePolicies[(string) $toolKey] = (string) $mode;
            }
        }

        return new EvalFixture(
            $id,
            $agent,
            (string) ($data['channel'] ?? 'webchat'),
            (string) ($data['locale'] ?? 'en'),
            is_array($data['principal'] ?? null) ? $data['principal'] : [],
            array_values($turns),
            $tags,
            $liveOnly,
            $path,
            $writePolicies,
        );
    }

    private function matchesFilter(EvalFixture $fixture, string $filter): bool
    {
        $needle = mb_strtolower($filter);
        if (str_contains(mb_strtolower($fixture->id), $needle)) {
            return true;
        }
        foreach ($fixture->tags as $tag) {
            if (str_contains(mb_strtolower($tag), $needle)) {
                return true;
            }
        }

        return false;
    }
}
