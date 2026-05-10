<?php

declare(strict_types=1);

namespace App\Explorer;

final class SignalProfileRegistry
{
    /** @var array<string, SignalProfile> */
    private array $byName = [];

    /**
     * @param iterable<SignalProfile> $profiles
     */
    public function __construct(iterable $profiles)
    {
        foreach ($profiles as $profile) {
            $this->byName[$profile->name()] = $profile;
        }
    }

    public function get(string $signal): SignalProfile
    {
        if (!isset($this->byName[$signal])) {
            throw new UnknownSignalException($signal);
        }

        return $this->byName[$signal];
    }

    public function has(string $signal): bool
    {
        return isset($this->byName[$signal]);
    }

    /** @return list<string> */
    public function knownSignals(): array
    {
        return array_keys($this->byName);
    }
}
