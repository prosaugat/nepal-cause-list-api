<?php

namespace NepalCauseList\Support;

/**
 * Minimal PSR-3-style static logger.
 *
 * The scraper services call Log::error()/info()/warning(). By default this
 * writes to PHP's error_log, but you can plug in any PSR-3 logger with
 * Log::setLogger($psrLogger) to route messages into your own stack.
 */
class Log
{
    /** @var object|null A PSR-3 LoggerInterface, if provided. */
    private static $logger = null;

    /** @var bool When true (default), fall back to error_log(). */
    private static bool $echoToErrorLog = true;

    public static function setLogger(?object $logger): void
    {
        self::$logger = $logger;
    }

    public static function silence(): void
    {
        self::$echoToErrorLog = false;
        self::$logger = null;
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$logger !== null && method_exists(self::$logger, $level)) {
            self::$logger->{$level}($message, $context);
            return;
        }
        if (self::$echoToErrorLog) {
            $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            error_log(sprintf('[nepal-cause-list] %s: %s%s', strtoupper($level), $message, $ctx));
        }
    }
}
