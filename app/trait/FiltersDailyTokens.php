<?php

namespace App\trait;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait FiltersDailyTokens
{
    /**
     * Resolve date range from request (defaults to current month).
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveDateRange(Request $request): array
    {
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : null;
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : null;

        if ($from && $to) {
            return [$from, $to];
        }

        if ($from) {
            return [$from, now()->toDateString()];
        }

        if ($to) {
            return [$startOfMonth, $to];
        }

        return [$startOfMonth, $endOfMonth];
    }

    /**
     * Apply date range filter to a daily tokens query.
     */
    protected function filterDailyTokensDate(Builder|\Illuminate\Database\Query\Builder $query, string $startDate, string $endDate)
    {
        return $query->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate);
    }
}
