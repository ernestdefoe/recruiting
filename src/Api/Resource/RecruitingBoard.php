<?php

namespace Ernestdefoe\Recruiting\Api\Resource;

use Closure;

/**
 * The recruiting board as the API resource sees it. Loaded on first read,
 * after the endpoint's permission check, and once for all its fields.
 */
class RecruitingBoard
{
    /** @var array{year: int, recruits: list<array<string, mixed>>, error: string|null}|null */
    private ?array $state = null;

    public function __construct(private readonly Closure $load)
    {
    }

    public function get(string $field): mixed
    {
        $this->state ??= ($this->load)();

        return $this->state[$field];
    }
}
