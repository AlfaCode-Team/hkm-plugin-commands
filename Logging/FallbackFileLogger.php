<?php

declare(strict_types=1);

namespace Plugins\Commands\Logging;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * FallbackFileLogger — the LoggerPort this plugin uses when the project has
 * bound none.
 *
 * WHY THIS EXISTS
 * ---------------
 * The commands below register from a container this plugin builds by hand in
 * Provider::boot(), which is NOT the container OnDemandLoader assembles from
 * the dependency graph. Nothing there runs another module's register(), so the
 * Logger plugin's LoggerPort binding — which it makes into a request-scoped
 * ModuleContainer — is not visible to us, and resolving the port threw
 * EntryNotFoundException before the CLI could list a single command.
 *
 * A logger that is merely NICE to have must never be the reason a CLI cannot
 * start. So the port is resolved when something bound it (a project's
 * ->withPorts([LoggerPort::class => ...]) reaches us through the CoreContainer)
 * and this file-backed default is used when nothing did.
 *
 * DELIBERATELY NOT A NULL LOGGER
 * ------------------------------
 * The bug this replaced was a NullLogger standing in for an absent binding, so
 * every command-audit line was silently discarded while logging LOOKED
 * configured. A fallback that writes nowhere reintroduces exactly that. This one
 * writes to var/logs/app.log — the same file the Logger plugin defaults to — so
 * an audit trail exists whether or not that plugin is installed.
 *
 * Never throws: a logger that fails must not take down the operation it was only
 * meant to observe.
 */
final class FallbackFileLogger implements LoggerPort
{
    private readonly string $file;
    private readonly LogLevel $minimum;

    public function __construct(?string $file = null, ?LogLevel $minimum = null)
    {
        $config = self::config();

        // Honour config/logger.php when the Logger plugin published it, so the
        // fallback and the real adapter write the same lines to the same file.
        // The 'channel' key is not honoured: the choice here is between logging
        // to a file and not existing, and there is no third option worth having.
        $this->file = $file
            ?? (($configured = (string) ($config['file'] ?? '')) !== ''
                ? $configured
                : Paths::logs('app.log'));

        $this->minimum = $minimum ?? LogLevel::parse((string) ($config['level'] ?? 'debug'));
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency->value, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Alert->value, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical->value, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error->value, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning->value, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice->value, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info->value, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug->value, $message, $context);
    }

    /**
     * Format:  [2026-08-06T11:22:33+00:00] warning: CLI command completed {"exit_code":1}
     *
     * The same shape the Logger plugin's FileLogger writes, so a file holding
     * lines from both stays readable.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $parsed = LogLevel::parse($level);

        if (!$parsed->passes($this->minimum)) {
            return;
        }

        $line = sprintf(
            '[%s] %s: %s %s',
            date(DATE_ATOM),
            $parsed->value,
            $this->interpolate((string) $message, $context),
            $this->encodeContext($context),
        );

        $this->append(rtrim($line) . PHP_EOL);
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /** @param array<string, mixed> $context */
    private function encodeContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        return json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            ?: '';
    }

    private function append(string $line): void
    {
        try {
            $dir = dirname($this->file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }

            @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Swallow — see the class docblock. There is nowhere left to report to.
        }
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        if (!\function_exists('config')) {
            return [];
        }

        $config = config('logger', []);

        return \is_array($config) ? $config : [];
    }
}
