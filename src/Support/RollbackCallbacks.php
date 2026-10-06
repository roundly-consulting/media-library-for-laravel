<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Runs a callback should the transaction open on a connection right now roll back: a savepoint's
 * own rollback, or an outer rollback that takes an already committed savepoint with it. Once the
 * outermost transaction commits, the callback is dropped. With no transaction open, nothing is
 * registered.
 *
 * Laravel tracks this itself from 12.32 on (`Connection::afterRollBack()`), and that hook is used
 * where it exists. Earlier 12.x releases have none, so there the same bookkeeping follows the
 * connection's `TransactionCommitted` / `TransactionRolledBack` events.
 *
 * @internal
 */
final class RollbackCallbacks
{
    /**
     * What the event fallback still holds, per connection: each callback with the transaction
     * level it belongs to.
     *
     * @var array<string, list<array{int, Closure(): void}>>
     */
    private array $pending = [];

    private bool $listening = false;

    /**
     * @param  bool|null  $frameworkHook  whether to use Laravel's own `afterRollBack()`; `null`
     *                                    uses it whenever the connection has it
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ?bool $frameworkHook = null,
    ) {}

    /**
     * @param  Closure(): void  $callback
     */
    public function register(Connection $connection, Closure $callback): void
    {
        $level = $connection->transactionLevel();

        if ($level === 0) {
            return;
        }

        if ($this->frameworkHook ?? self::hasFrameworkHook($connection)) {
            $connection->afterRollBack($callback);

            return;
        }

        $this->listen();

        $this->pending[(string) $connection->getName()][] = [$level, $callback];
    }

    private static function hasFrameworkHook(object $connection): bool
    {
        return method_exists($connection, 'afterRollBack');
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        $this->events->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $this->committed($event->connectionName, $event->connection->transactionLevel());
        });

        $this->events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $this->rolledBack($event->connectionName, $event->connection->transactionLevel());
        });
    }

    /**
     * A savepoint's commit hands its callbacks to the transaction it is part of — a later
     * rollback of a sibling savepoint must not run them. The outermost commit drops them.
     */
    private function committed(string $connection, int $level): void
    {
        if ($level === 0) {
            unset($this->pending[$connection]);

            return;
        }

        foreach ($this->pending[$connection] ?? [] as $index => [$at, $callback]) {
            if ($at > $level) {
                $this->pending[$connection][$index] = [$level, $callback];
            }
        }
    }

    /** Run the callbacks of every level the rollback undid. */
    private function rolledBack(string $connection, int $level): void
    {
        $due = [];
        $kept = [];

        foreach ($this->pending[$connection] ?? [] as $entry) {
            if ($entry[0] > $level) {
                $due[] = $entry[1];
            } else {
                $kept[] = $entry;
            }
        }

        $this->pending[$connection] = $kept;

        foreach ($due as $callback) {
            $callback();
        }
    }
}
