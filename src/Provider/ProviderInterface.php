<?php

declare(strict_types=1);

namespace FreeSms\Provider;

/**
 * Contract implemented by every SMS source.
 *
 * A provider knows how to list available US phone numbers and how to read
 * the latest messages for a given number, normalising both into a common
 * array shape (see AbstractProvider for the documented shapes).
 */
interface ProviderInterface
{
    /** Stable machine identifier, e.g. "receive-sms.cc". */
    public function slug(): string;

    /** Human-readable name, e.g. "Receive SMS CC". */
    public function name(): string;

    /**
     * List available US phone numbers.
     *
     * @return array<int,array{
     *     source:string, number:string, display:string,
     *     link:string, messages:?int, last_seen:?string
     * }>
     */
    public function getUsNumbers(int $limit = 30): array;

    /**
     * Read the latest messages for a number (digits only).
     *
     * @return array<int,array{
     *     source:string, from:string, time:string, text:string, code:?string
     * }>
     */
    public function getMessages(string $number, int $limit = 20): array;

    /** First available US number, or null when none is listed. */
    public function getFirstUsNumber(): ?array;
}
