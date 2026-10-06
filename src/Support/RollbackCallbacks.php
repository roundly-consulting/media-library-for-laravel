<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Runs a callback should the transaction open on a connection right now roll back: a savepoint's
 * own rollback, or an outer rollback that takes an already committed savepoint with it. Once the
 * outermost transaction commits, the callback is dropped. With no transaction open, nothing is
 * registered.
 *
 * Laravel's own `Connection::afterRollBack()` is not used: before 13.21 (all of 12.x included) it
 * loses the callbacks of a committed savepoint when the outer transaction rolls back. The
 * connection's `TransactionCommitted` / `TransactionRolledBack` events (wired in the service
 * provider) drive the same bookkeeping on every supported version.
 *
 * @internal
 */
final class RollbackCallbacks
{
    /**
     * Callbacks per connection, each with the transaction level it belongs to.
     *
     * @var array<string, list<array{int, Closure(): void}>>
     */
    private array $pending = [];

    /**
     * @param  Closure(): void  $callback
     */
    public function register(Connection $connection, Closure $callback): void
    {
        $level = $connection->transactionLevel();

        if ($level === 0) {
            return;
        }

        $this->pending[(string) $connection->getName()][] = [$level, $callback];
    }

    /**
     * A savepoint's commit hands its callbacks to the transaction it is part of, so a later
     * rollback of a sibling savepoint leaves them alone. The outermost commit drops them.
     */
    public function committed(TransactionCommitted $event): void
    {
        $connection = $event->connectionName;
        $level = $event->connection->transactionLevel();

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
    public function rolledBack(TransactionRolledBack $event): void
    {
        $connection = $event->connectionName;
        $level = $event->connection->transactionLevel();

        $due = [];
        $kept = [];

        foreach ($this->pending[$connection] ?? [] as $entry) {
            if ($entry[0] > $level) {
                $due[] = $entry[1];
            } else {
                $kept[] = $entry;
            }
        }

        if ($kept === []) {
            unset($this->pending[$connection]);
        } else {
            $this->pending[$connection] = $kept;
        }

        foreach ($due as $callback) {
            $callback();
        }
    }
}
