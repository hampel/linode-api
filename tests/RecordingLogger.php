<?php

declare(strict_types=1);

namespace Hampel\Linode\Api\Tests;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps what it was told, so a test can assert on it.
 *
 * The parameters are deliberately untyped. psr/log 1.1 declares log() without types and
 * psr/log 3 declares `string|\Stringable $message`; an untyped parameter is wider than
 * both, so this one class satisfies every version the package supports - including psr/log
 * 1.1, which is what the --prefer-lowest corner in CI resolves to.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  mixed  $message
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => is_scalar($message) || $message instanceof \Stringable ? (string) $message : '',
            'context' => $context,
        ];
    }

    /**
     * Every record logged above `debug` - which is what would reach an alerting channel, and
     * what this package promises never to write.
     *
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function aboveDebug(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] !== \Psr\Log\LogLevel::DEBUG
        ));
    }

    /**
     * The level of the first record with this message, or null if there was none.
     */
    public function levelFor(string $message): mixed
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['level'];
            }
        }

        return null;
    }

    /**
     * The context of the first record with this message, or null if there was none.
     *
     * @return array<string, mixed>|null
     */
    public function contextFor(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }

        return null;
    }
}
