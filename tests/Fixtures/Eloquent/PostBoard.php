<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Tests\Fixtures\Eloquent;

/**
 * Stands in for the application code a single HTTP request runs: a controller,
 * an API resource or a blade view that walks a collection of models and
 * touches a relation on each one.
 *
 * The line numbers below are asserted on, because the whole point is that the
 * report blames this file and not the framework or the test.
 */
final class PostBoard
{
    /**
     * One request, one query for the posts — and then one more per post,
     * because the relation is loaded lazily inside the loop.
     *
     * @return list<int>
     */
    public function withLazyComments(): array
    {
        $counts = [];

        foreach (Post::all() as $post) {
            $counts[] = $post->comments->count();
        }

        return $counts;
    }

    /**
     * The same screen, eager loaded: two queries no matter how many posts.
     *
     * @return list<int>
     */
    public function withEagerComments(): array
    {
        $counts = [];

        foreach (Post::with('comments')->get() as $post) {
            $counts[] = $post->comments->count();
        }

        return $counts;
    }
}
