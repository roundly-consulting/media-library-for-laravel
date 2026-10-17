<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Testing;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Support\RemoteUrlGuard;

/**
 * The DNS table {@see MediaLibrary::fakeDns()} installs: the remote URL guard behind
 * `addFromUrl()`, `MediaLibrary::remote()` and the `SafeRemoteUrl` rule asks it instead of DNS.
 *
 * A name it was given resolves to exactly the addresses pinned for it, and the guard judges them
 * as it judges real answers — a private one is refused. **A name it was not given does not
 * resolve**, so a test never reaches real DNS. Address literals, `localhost` and names in
 * `media.remote.allowed_private_hosts` are never looked up, so they never reach the table.
 *
 * Only DNS is faked. The requests themselves go through Laravel's HTTP client: answer them with
 * `Http::fake()`. The table lives in the application container, so it ends with the test that
 * installed it.
 */
final class DnsFake
{
    /** @var array<string, list<string>> host name => the addresses it resolves to */
    private array $answers = [];

    /** @var list<string> every name the guard looked up, in order */
    private array $lookups = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Install the table into `$container` — the one already there, if any, so pins add up — and
     * pin `$records`.
     *
     * @param  array<string, string|list<string>>  $records  host name => its address(es); `[]` = unresolvable
     *
     * @internal called by {@see MediaLibrary::fakeDns()}
     */
    public static function install(Container $container, array $records = []): self
    {
        $fake = $container->bound(self::class) ? $container->make(self::class) : new self($container);

        $container->instance(self::class, $fake);
        $container->instance(HostResolver::class, new HostResolver($fake->lookup(...)));

        foreach ($records as $host => $addresses) {
            // PHP turns an all-digit key into an int; the guard refuses such a name anyway.
            $host = (string) $host;
            $addresses === [] ? $fake->unresolvable($host) : $fake->resolve($host, $addresses);
        }

        return $fake;
    }

    /**
     * Make `$host` resolve to `$addresses` (replacing an earlier pin for it). The name is matched
     * the way the guard writes it: lower case, without a trailing dot, an internationalised name
     * in its punycode form.
     *
     * @param  string|list<string>  $addresses  IPv4 or IPv6 addresses, IPv6 without brackets
     *
     * @throws InvalidArgumentException for a name the guard would never look up, or a non-address
     */
    public function resolve(string $host, string|array $addresses): self
    {
        $name = $this->name($host);
        $addresses = is_string($addresses) ? [$addresses] : $addresses;

        if ($addresses === []) {
            throw new InvalidArgumentException("Pin at least one address for [{$host}], or call unresolvable().");
        }

        $pinned = array_map(static fn (mixed $address): string => self::address($host, $address), $addresses);

        $this->answers[$name] = array_values(array_unique($pinned));

        return $this;
    }

    /**
     * Make `$host` not resolve — what every name the table was not given does already; use it to
     * drop an earlier pin.
     *
     * @throws InvalidArgumentException for a name the guard would never look up
     */
    public function unresolvable(string $host): self
    {
        $this->answers[$this->name($host)] = [];

        return $this;
    }

    /**
     * Every name the guard looked up, in order, as it asked for it (one entry per lookup).
     *
     * @return list<string>
     */
    public function lookups(): array
    {
        return $this->lookups;
    }

    /** `$host` was looked up — at least once, or exactly `$times` times. */
    public function assertLookedUp(string $host, ?int $times = null): void
    {
        $name = $this->name($host);
        $count = $this->countOf($name);

        if ($times === null) {
            Assert::assertGreaterThan(0, $count, "Expected [{$name}] to be looked up, but it was not.");

            return;
        }

        Assert::assertSame($times, $count, "Expected [{$name}] to be looked up {$times} time(s), but it was looked up {$count} time(s).");
    }

    public function assertNotLookedUp(string $host): void
    {
        $name = $this->name($host);
        $count = $this->countOf($name);

        Assert::assertSame(0, $count, "Expected [{$name}] not to be looked up, but it was looked up {$count} time(s).");
    }

    public function assertNothingLookedUp(): void
    {
        $count = count($this->lookups);

        Assert::assertSame(0, $count, "Expected no DNS lookups, but {$count} were made: ".implode(', ', $this->lookups).'.');
    }

    /**
     * The guard's lookup: the pinned addresses, or none.
     *
     * @return list<string>
     */
    private function lookup(string $host): array
    {
        $this->lookups[] = $host;

        return $this->answers[$host] ?? [];
    }

    /**
     * `$address` in its canonical spelling, as a real lookup reports it. Typed `mixed`: nothing
     * stops a caller handing in something that is not a string.
     */
    private static function address(string $host, mixed $address): string
    {
        $bytes = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? @inet_pton($address) : false;

        if ($bytes === false) {
            $shown = is_scalar($address) ? (string) $address : get_debug_type($address);

            throw new InvalidArgumentException("Cannot pin [{$host}] to [{$shown}]: not an IP address.");
        }

        return (string) inet_ntop($bytes);
    }

    private function countOf(string $name): int
    {
        return count(array_keys($this->lookups, $name, true));
    }

    /** `$host` as the guard asks for it, or a refusal when the guard would never ask for it. */
    private function name(string $host): string
    {
        // Anything that would make it more than a host in a URL: a port, a path, credentials, brackets.
        if (preg_match('#[/?\#@:\[\]]#', $host) === 1) {
            throw self::notAHostName($host);
        }

        try {
            $canonical = $this->container->make(RemoteUrlGuard::class)->canonicalise("http://{$host}/");
        } catch (RemoteFetchFailed) {
            throw self::notAHostName($host);
        }

        if ($canonical->ipLiteral) {
            throw new InvalidArgumentException("Cannot pin [{$host}]: it is an address, and the guard never looks an address up.");
        }

        return $canonical->host;
    }

    private static function notAHostName(string $host): InvalidArgumentException
    {
        return new InvalidArgumentException("Cannot pin [{$host}]: not a host name the guard would look up.");
    }
}
