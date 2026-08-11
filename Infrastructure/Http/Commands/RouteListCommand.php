<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Http\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Depends\Colors;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * route:list — display every route compiled into the route manifest.
 *
 * The kernel compiles all plugin + project routes into
 * `var/cache/manifests/route-manifest.php` (CompileRouteManifestStage). That file
 * is the single source of truth for "what URLs does this app answer", so this
 * command reads it directly rather than re-parsing module.json files — run a boot
 * (any entry point) first so the manifest exists.
 *
 * Usage:
 *   hkm route:list
 *   hkm route:list --method=GET
 *   hkm route:list --path=/api
 *   hkm route:list --domain=shop.local
 *   hkm route:list --named
 *   hkm route:list --json
 *
 * Each manifest entry is keyed by "METHOD /path" — or "METHOD@domain /path" when
 * the route belongs to a DOMAIN GROUP — and carries:
 *   handler, module, solves, name, filters[], requires[], faces[], domain, overrides
 */
final class RouteListCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'route:list';
        $this->description = 'List all routes compiled into the route manifest';
        $this->help        = <<<'HELP'
Reads the compiled route manifest (var/cache/manifests/route-manifest.php) and
prints every registered route with its handler, owning module/scope, filters and
per-route module requires.

Project routes resolve under the synthetic "__project__" scope; a project route
that overrides a plugin route shows the overridden module.

A route grouped under a domain shows that host in the Domain column; an
UNGROUPED route shows "*" because every host reaches it.

Options:
  --method=VERB   Only routes matching this HTTP method (case-insensitive)
  --path=PREFIX   Only routes whose path starts with PREFIX
  --domain=HOST   Only routes reachable on HOST — its domain groups (exact,
                  wildcard or bare subdomain) plus every ungrouped route
  --filter=ALIAS  Only routes running that filter (auth, throttle, ...)
  --named         Only routes addressable by route('name')
  --json          Emit the raw manifest as JSON (for scripting)

Examples:
  hkm route:list
  hkm route:list --method=POST
  hkm route:list --path=/api/invoices
  hkm route:list --domain=organizer.africavoting.local
  hkm route:list --filter=auth
  hkm route:list --named
  hkm route:list --json
HELP;

        $this->addOption('method', 'm', 'Filter by HTTP method', acceptsValue: true);
        $this->addOption('path',   'p', 'Filter by path prefix',  acceptsValue: true);
        $this->addOption('domain', 'd', 'Only routes reachable on this host', acceptsValue: true);
        $this->addOption('filter', 'f', 'Only routes running this filter alias', acceptsValue: true);
        $this->addOption('named',  'n', 'Only named routes');
        $this->addOption('json',   'j', 'Output the manifest as JSON');
    }

    protected function handle(): int
    {
        $manifest = $this->loadManifest();

        if ($manifest === null) {
            $this->alertWarning('Route manifest not found', [
                'Expected: ' . Paths::cache('manifests/route-manifest.php'),
                'Boot the app once (any entry point) to compile it, then retry.',
            ]);
            return self::FAILURE;
        }

        $rows = $this->filterRoutes($manifest);

        if ($this->hasOption('json')) {
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No routes match the given filters.');
            return self::SUCCESS;
        }

        $this->section('Registered routes');

        $tableRows = [];
        foreach ($rows as $key => $entry) {
            ['method' => $method, 'domain' => $domain, 'path' => $path] = RouteIndex::parseKey((string) $key);

            $tableRows[] = [
                $this->colorMethod($method),
                // '*' — an ungrouped route is GLOBAL: every host reaches it.
                $domain === '' ? Colors::muted('*') : Colors::wrap($domain, Colors::CYAN),
                $path,
                (string) ($entry['name'] ?? '') !== '' ? (string) $entry['name'] : Colors::muted('—'),
                (string) ($entry['handler'] ?? '—'),
                $this->scopeLabel($entry),
                $this->listLabel($entry['filters'] ?? []),
            ];
        }

        $this->table()
            ->headers(['Method', 'Domain', 'Path', 'Name', 'Handler', 'Scope', 'Filters'])
            ->rows($tableRows)
            ->render();

        $this->muted('  ' . count($rows) . ' route' . (count($rows) === 1 ? '' : 's') . ' shown');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function loadManifest(): ?array
    {
        $path = Paths::cache('manifests/route-manifest.php');

        if (!is_file($path)) {
            return null;
        }

        /** @var mixed $manifest */
        $manifest = require $path;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     * @return array<string, array<string, mixed>>
     */
    private function filterRoutes(array $manifest): array
    {
        $methodFilter = strtoupper(trim((string) $this->option('method', '')));
        $pathFilter   = (string) $this->option('path', '');
        $filterAlias  = trim((string) $this->option('filter', ''));
        $host         = trim((string) $this->option('domain', ''));
        $namedOnly    = $this->hasOption('named');

        // The domain groups this host could match, most specific first — the same
        // expansion the router performs, so the listing matches what it will serve.
        $hostGroups = $host === '' ? [] : RouteIndex::hostCandidates($host);

        $filtered = [];

        foreach ($manifest as $key => $entry) {
            ['method' => $method, 'domain' => $domain, 'path' => $path] = RouteIndex::parseKey((string) $key);

            if ($methodFilter !== '' && strtoupper($method) !== $methodFilter) {
                continue;
            }
            if ($pathFilter !== '' && !str_starts_with($path, $pathFilter)) {
                continue;
            }
            if ($namedOnly && ($entry['name'] ?? null) === null) {
                continue;
            }
            // An ungrouped route answers on every host, so it belongs in every
            // host's listing — exactly how the matcher treats it.
            if ($hostGroups !== [] && $domain !== '' && !in_array($domain, $hostGroups, true)) {
                continue;
            }
            if ($filterAlias !== '' && !in_array($filterAlias, $this->aliases($entry), true)) {
                continue;
            }

            $filtered[$key] = $entry;
        }

        // Deterministic order: domain, then path, then method — so everything
        // answering one host reads together.
        uksort($filtered, static function (string $a, string $b): int {
            $pa = RouteIndex::parseKey($a);
            $pb = RouteIndex::parseKey($b);
            return [$pa['domain'], $pa['path'], $pa['method']]
               <=> [$pb['domain'], $pb['path'], $pb['method']];
        });

        return $filtered;
    }

    /**
     * The filter ALIASES a route runs — "throttle:60,1" is the alias "throttle".
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function aliases(array $entry): array
    {
        // The compiler precomputes these; fall back to parsing for a manifest
        // compiled by an older kernel.
        $specs = $entry['filter_specs'] ?? null;
        if (is_array($specs)) {
            return array_map(static fn($s): string => (string) ($s['alias'] ?? ''), $specs);
        }

        return array_map(
            static fn($f): string => explode(':', trim((string) $f), 2)[0],
            is_array($entry['filters'] ?? null) ? $entry['filters'] : [],
        );
    }

    private function colorMethod(string $method): string
    {
        $method = strtoupper($method);

        return match ($method) {
            'GET'    => Colors::wrap($method, Colors::GREEN),
            'POST'   => Colors::wrap($method, Colors::BLUE),
            'PUT',
            'PATCH'  => Colors::wrap($method, Colors::YELLOW),
            'DELETE' => Colors::wrap($method, Colors::RED),
            default  => Colors::muted($method),
        };
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function scopeLabel(array $entry): string
    {
        $solves = (string) ($entry['solves'] ?? '');

        if ($solves === '__project__') {
            $overrides = $entry['overrides'] ?? null;
            $label     = Colors::wrap('project', Colors::CYAN);
            return $overrides !== null
                ? $label . Colors::muted(' (overrides ' . $this->shortClass((string) $overrides) . ')')
                : $label;
        }

        return $solves !== '' ? $solves : Colors::muted('—');
    }

    /**
     * @param mixed $list
     */
    private function listLabel(mixed $list): string
    {
        if (!is_array($list) || $list === []) {
            return Colors::muted('—');
        }

        return implode(', ', array_map(static fn($v): string => (string) $v, $list));
    }

    private function shortClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts) ?: $class;
    }
}
