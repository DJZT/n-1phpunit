<?php

declare(strict_types=1);

namespace NPlusOne\PHPUnit\Report;

use NPlusOne\PHPUnit\Analysis\Detection;
use NPlusOne\PHPUnit\Analysis\Hotspot;
use NPlusOne\PHPUnit\Configuration\Options;
use RuntimeException;

/**
 * A single self-contained page: no network, no assets, opens from a file:// URL
 * or straight out of a CI artefact. Everything a run found, with search,
 * filtering and sorting, because a hundred-odd hotspots do not fit in a
 * terminal scroll-back.
 */
final class HtmlReporter
{
    /** A hotspot hit by hundreds of tests would otherwise bloat the page. */
    private const TESTS_SHOWN = 20;

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function render(array $hotspots, Statistics $statistics): string
    {
        $generated = date('Y-m-d H:i');

        return implode(PHP_EOL, [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>N+1 query report</title>',
            '<style>' . self::styles() . '</style>',
            '</head>',
            '<body>',
            $this->header($statistics, $generated),
            $this->controls($hotspots),
            $this->hotspots($hotspots),
            '<script>' . self::script() . '</script>',
            '</body>',
            '</html>',
            '',
        ]);
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    public function write(string $path, array $hotspots, Statistics $statistics): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the report directory "%s".', $directory));
        }

        if (@file_put_contents($path, $this->render($hotspots, $statistics)) === false) {
            throw new RuntimeException(sprintf('Unable to write the HTML report to "%s".', $path));
        }
    }

    private function header(Statistics $statistics, string $generated): string
    {
        $cards = [
            ['hotspots', (string) $statistics->hotspots, 'places to fix'],
            ['tests analysed', (string) $statistics->analysedTests, 'in this run'],
            ['tests affected', (string) $statistics->affectedTests, 'hit a hotspot'],
            ['queries recorded', number_format($statistics->recordedQueries), 'in analysed tests'],
        ];

        $html = '<header class="top"><h1>N+1 query report</h1>'
            . '<p class="generated">generated ' . $this->escape($generated)
            . ' · threshold ' . $this->options->threshold . '+ repeats in one test</p><div class="cards">';

        foreach ($cards as [$label, $value, $hint]) {
            $html .= '<div class="card"><span class="value">' . $this->escape($value) . '</span>'
                . '<span class="label">' . $this->escape($label) . '</span>'
                . '<span class="hint">' . $this->escape($hint) . '</span></div>';
        }

        return $html . '</div></header>';
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    private function controls(array $hotspots): string
    {
        $nPlusOne = count(array_filter($hotspots, static fn (Hotspot $h): bool => $h->kind() === Detection::KIND_N_PLUS_ONE));
        $duplicates = count($hotspots) - $nPlusOne;

        return '<div class="controls">'
            . '<input type="search" id="search" placeholder="filter by file, query, test or framework call…" autocomplete="off">'
            . '<div class="kinds">'
            . '<button class="chip is-active" data-kind="all">all <b>' . count($hotspots) . '</b></button>'
            . '<button class="chip" data-kind="N+1">N+1 <b>' . $nPlusOne . '</b></button>'
            . '<button class="chip" data-kind="duplicate">duplicate <b>' . $duplicates . '</b></button>'
            . '</div>'
            . '<label class="sort">sort by '
            . '<select id="sort">'
            . '<option value="worst">worst single test</option>'
            . '<option value="total">queries overall</option>'
            . '<option value="time">time spent</option>'
            . '<option value="tests">tests affected</option>'
            . '</select></label>'
            . '<p class="counter" id="counter"></p>'
            . '</div>';
    }

    /**
     * @param list<Hotspot> $hotspots
     */
    private function hotspots(array $hotspots): string
    {
        if ($hotspots === []) {
            return '<p class="empty">No repeated query patterns found.</p>';
        }

        $html = '<main id="list">';
        $rank = 0;

        foreach ($hotspots as $hotspot) {
            ++$rank;
            $html .= $this->hotspot($rank, $hotspot);
        }

        return $html . '</main>';
    }

    private function hotspot(int $rank, Hotspot $hotspot): string
    {
        $isNPlusOne = $hotspot->kind() === Detection::KIND_N_PLUS_ONE;
        $origin = $hotspot->origin->relative($this->options->basePath);
        $tests = $hotspot->tests();

        $searchable = strtolower(implode(' ', [
            $origin,
            (string) $hotspot->origin->function,
            (string) $hotspot->origin->via,
            $hotspot->sql,
            implode(' ', array_keys($tests)),
        ]));

        $html = sprintf(
            '<article class="hotspot" data-kind="%s" data-worst="%d" data-total="%d" data-time="%s" data-tests="%d" data-text="%s">',
            $this->escape($hotspot->kind()),
            $hotspot->maxCount(),
            $hotspot->totalCount(),
            $this->escape((string) round($hotspot->totalTimeMs(), 3)),
            count($tests),
            $this->escape($searchable),
        );

        $html .= '<div class="head">'
            . '<span class="rank">#' . $rank . '</span>'
            . '<span class="count">' . $hotspot->maxCount() . '<small>x</small></span>'
            . '<span class="kind ' . ($isNPlusOne ? 'kind--nplusone' : 'kind--duplicate') . '">'
            . $this->escape($hotspot->kind()) . '</span>'
            . '<span class="origin">' . $this->escape($origin) . '</span>';

        if ($hotspot->origin->function !== null) {
            $html .= '<span class="fn">' . $this->escape($hotspot->origin->function) . '</span>';
        }

        $html .= '</div>';

        if ($hotspot->origin->via !== null) {
            $html .= '<p class="via">via ' . $this->escape($hotspot->origin->via) . '</p>';
        }

        $html .= '<pre class="sql">' . $this->escape(trim((string) preg_replace('/\s+/', ' ', $hotspot->sql))) . '</pre>';

        $html .= '<p class="meta">'
            . '<span title="the worst single test — what one request costs"><b>' . $hotspot->maxCount() . '</b> in one test</span>'
            . '<span><b>' . $hotspot->totalCount() . '</b> queries overall</span>'
            . '<span><b>' . $this->escape(number_format($hotspot->totalTimeMs(), 2)) . '</b> ms</span>'
            . '<span><b>' . count($tests) . '</b> test' . (count($tests) === 1 ? '' : 's') . '</span>';

        if ($hotspot->connection !== null) {
            $html .= '<span class="conn">' . $this->escape($hotspot->connection) . '</span>';
        }

        $html .= '</p>';

        return $html . $this->tests($tests) . '</article>';
    }

    /**
     * @param array<string, int> $tests
     */
    private function tests(array $tests): string
    {
        $shown = array_slice($tests, 0, self::TESTS_SHOWN, true);
        $hidden = count($tests) - count($shown);

        $html = '<details class="tests"><summary>tests that hit this place</summary><ul>';

        foreach ($shown as $test => $count) {
            $html .= '<li><code>' . $this->escape($test) . '</code><b>' . $count . 'x</b></li>';
        }

        if ($hidden > 0) {
            $html .= '<li class="more">and ' . $hidden . ' more test' . ($hidden === 1 ? '' : 's') . '</li>';
        }

        return $html . '</ul></details>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function styles(): string
    {
        return <<<'CSS'
        :root{--bg:#f6f7f9;--fg:#1c2024;--muted:#6b7280;--card:#fff;--line:#e3e6ea;--accent:#b4232b;--warn:#9a6700;--code:#f2f3f5}
        @media (prefers-color-scheme:dark){:root{--bg:#15171a;--fg:#e6e8ea;--muted:#9aa2ad;--card:#1d2024;--line:#2b2f35;--accent:#ff6b6b;--warn:#e0a300;--code:#111316}}
        *{box-sizing:border-box}
        body{margin:0;padding:24px;background:var(--bg);color:var(--fg);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
        .top h1{margin:0 0 4px;font-size:20px}
        .generated{margin:0 0 16px;color:var(--muted);font-size:13px}
        .cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px}
        .card{flex:1 1 160px;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column}
        .card .value{font-size:24px;font-weight:600;line-height:1.1}
        .card .label{font-size:13px}
        .card .hint{color:var(--muted);font-size:12px}
        .controls{position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:var(--bg);padding:10px 0;border-bottom:1px solid var(--line);margin-bottom:14px}
        #search{flex:1 1 320px;min-width:200px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:inherit;font:inherit}
        .kinds{display:flex;gap:6px}
        .chip{border:1px solid var(--line);background:var(--card);color:inherit;border-radius:999px;padding:6px 12px;cursor:pointer;font:inherit}
        .chip.is-active{border-color:var(--fg)}
        .chip b{font-weight:600;opacity:.6;margin-left:4px}
        .sort{color:var(--muted);font-size:13px}
        .sort select{font:inherit;padding:6px 8px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:inherit}
        .counter{margin:0;color:var(--muted);font-size:13px;flex-basis:100%}
        .hotspot{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px}
        .hotspot[hidden]{display:none}
        .head{display:flex;flex-wrap:wrap;gap:10px;align-items:baseline}
        .rank{color:var(--muted);font-variant-numeric:tabular-nums}
        .count{font-size:18px;font-weight:700;font-variant-numeric:tabular-nums}
        .count small{font-weight:400;font-size:12px;color:var(--muted)}
        .kind{font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid currentColor}
        .kind--nplusone{color:var(--accent)}
        .kind--duplicate{color:var(--warn)}
        .origin{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;word-break:break-all}
        .fn{color:var(--muted);font-size:12px}
        .via{margin:6px 0 0;color:var(--muted);font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .sql{margin:8px 0;padding:10px;background:var(--code);border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere}
        .meta{margin:0;display:flex;flex-wrap:wrap;gap:14px;color:var(--muted);font-size:12.5px}
        .meta b{color:var(--fg);font-variant-numeric:tabular-nums}
        .conn{opacity:.8}
        .tests{margin-top:8px}
        .tests summary{cursor:pointer;color:var(--muted);font-size:12.5px}
        .tests ul{margin:8px 0 0;padding-left:0;list-style:none}
        .tests li{display:flex;justify-content:space-between;gap:12px;padding:3px 0;border-bottom:1px dashed var(--line);font-size:12.5px}
        .tests code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}
        .tests .more{color:var(--muted);border:0}
        .empty{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px;text-align:center}
        CSS;
    }

    private static function script(): string
    {
        return <<<'JS'
        (function () {
            var list = document.getElementById('list');
            if (!list) { return; }

            var rows = Array.prototype.slice.call(list.querySelectorAll('.hotspot'));
            var search = document.getElementById('search');
            var sort = document.getElementById('sort');
            var counter = document.getElementById('counter');
            var chips = Array.prototype.slice.call(document.querySelectorAll('.chip'));
            var kind = 'all';

            function apply() {
                var needle = search.value.trim().toLowerCase();
                var shown = 0;

                rows.forEach(function (row) {
                    var matches = (kind === 'all' || row.dataset.kind === kind)
                        && (needle === '' || row.dataset.text.indexOf(needle) !== -1);

                    row.hidden = !matches;
                    if (matches) { shown++; }
                });

                counter.textContent = shown + ' of ' + rows.length + ' shown';
            }

            function reorder() {
                var key = sort.value;
                var sorted = rows.slice().sort(function (a, b) {
                    return parseFloat(b.dataset[key === 'worst' ? 'worst' : key]) - parseFloat(a.dataset[key === 'worst' ? 'worst' : key]);
                });

                sorted.forEach(function (row) { list.appendChild(row); });
            }

            search.addEventListener('input', apply);
            sort.addEventListener('change', function () { reorder(); apply(); });

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    kind = chip.dataset.kind;
                    chips.forEach(function (other) { other.classList.toggle('is-active', other === chip); });
                    apply();
                });
            });

            apply();
        })();
        JS;
    }
}
