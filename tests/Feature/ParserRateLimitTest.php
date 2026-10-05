<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ParserRateLimitTest extends TestCase
{
    public function test_parser_budgets_are_separate_from_each_other_and_public_api(): void
    {
        $limiter = RateLimiter::limiter('api');
        $limits = [];
        foreach (['api/internal/parser-jobs/claim', 'api/internal/sync-runs/1/partial-result', 'api/user'] as $path) {
            $request = Request::create('/'.$path, 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
            $request->setUserResolver(fn () => null);
            $limits[] = $limiter($request);
        }
        $this->assertSame([300, 1200, 60], array_map(fn ($limit) => $limit->maxAttempts, $limits));
        $this->assertCount(3, array_unique(array_map(fn ($limit) => $limit->key, $limits)));
    }
}
