<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SuggestionService
{
    private const MAX_SUGGESTIONS = 8;

    /**
     * Maps suggestion categories to keyword lists that match column/table names.
     *
     * Keywords are intentionally bilingual (English + French) because the
     * platform serves French-speaking hotel clients whose database schemas
     * often mix both languages (e.g. `chiffre_affaires`, `taux_occupation`).
     * English-only matching would silently misclassify or skip such columns,
     * producing awkward or missing suggestions. Both languages are listed
     * here explicitly rather than using a translation layer to keep the
     * matching stateless, obvious, and easy to extend for additional terms
     * or languages.
     */
    private const CATEGORY_KEYWORDS = [
        'Revenue & Financial' => [
            'revenue', 'amount', 'payment', 'invoice', 'rate', 'ca',
            'chiffre', 'prix', 'cost', 'income', 'facture', 'total',
            'price', 'tarif', 'recette', 'transaction',
        ],
        'Occupancy & Bookings' => [
            'reservation', 'booking', 'occupancy', 'stay', 'arrival',
            'sejour', 'occupation', 'booking_status', 'check_in',
            'check_out', 'departure', 'room', 'chamber',
            'occupancy_rate', 'occupation_rate', 'availability',
        ],
    ];

    private const DATE_TYPES = ['date', 'datetime', 'timestamp', 'year', 'time'];
    private const NUMERIC_TYPES = [
        'int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint',
        'decimal', 'numeric', 'float', 'double', 'real',
    ];

    public function generate(array $schema): array
    {
        $tables = $schema['tables'] ?? [];
        $sensitiveTables = $schema['sensitive_tables'] ?? [];
        $sensitiveColumns = $schema['sensitive_columns'] ?? [];

        $suggestions = [];
        $seenQueries = [];

        foreach ($tables as $tableName => $table) {
            if (in_array($tableName, $sensitiveTables, true)) {
                continue;
            }

            $rowCount = $table['row_count'] ?? null;
            if ($rowCount === 0) {
                continue;
            }

            $columns = $this->filterSensitiveColumns(
                $table['columns'] ?? [],
                $sensitiveColumns,
                $tableName,
            );

            if (empty($columns)) {
                continue;
            }

            $subject = $this->subjectName($table, $tableName);

            $category = $this->assignCategory($table, $tableName);

            $this->addSummarySuggestion($suggestions, $seenQueries, $subject, $category, $subject);

            $this->addTrendSuggestion($suggestions, $seenQueries, $columns, $subject, $category);

            $this->addBreakdownSuggestion($suggestions, $seenQueries, $columns, $subject, $category);

            $this->addAverageSuggestion($suggestions, $seenQueries, $columns, $subject, $category);

            $this->addTopSuggestion($suggestions, $seenQueries, $columns, $subject, $category);

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        $suggestions = array_slice($suggestions, 0, self::MAX_SUGGESTIONS);

        if (empty($suggestions)) {
            $suggestions = $this->fallbackSuggestions();
        }

        return $suggestions;
    }

    private function filterSensitiveColumns(
        array $columns,
        array $sensitiveColumns,
        string $tableName,
    ): array {
        $globalBlocked = $sensitiveColumns['*'] ?? [];
        $tableBlocked = $sensitiveColumns[$tableName] ?? [];

        return array_filter($columns, function (array $col) use ($globalBlocked, $tableBlocked) {
            if ($col['is_sensitive'] ?? false) {
                return false;
            }
            $name = $col['name'] ?? '';
            if (in_array($name, $globalBlocked, true)) {
                return false;
            }
            if (in_array($name, $tableBlocked, true)) {
                return false;
            }
            return true;
        });
    }

    private function subjectName(array $table, string $tableName): string
    {
        $desc = $table['description'] ?? '';
        if ($desc) {
            $parts = preg_split('/\s*table\.?\s*/i', $desc, 2);
            if (!empty($parts[0]) && stripos($parts[0], 'table') === false) {
                $candidate = trim($parts[0]);
                if (strlen($candidate) > 2 && strlen($candidate) < 60) {
                    return $candidate;
                }
            }
        }

        return $this->humanizeName($tableName);
    }

    private function humanizeName(string $name): string
    {
        $result = str_replace('_', ' ', $name);
        return ucwords($result);
    }

    private function assignCategory(array $table, string $tableName): string
    {
        $haystack = strtolower($tableName . ' ' . ($table['description'] ?? ''));

        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $category;
                }
            }
        }

        return 'Operations';
    }

    private function addSummarySuggestion(
        array &$suggestions,
        array &$seenQueries,
        string $subject,
        string $category,
        string $dedupKey,
    ): void {
        $query = "Summarize the {$dedupKey} data";
        $this->addIfUnique($suggestions, $seenQueries, [
            'label' => "Summarize {$dedupKey}",
            'query' => $query,
            'category' => $category,
        ]);
    }

    private function addTrendSuggestion(
        array &$suggestions,
        array &$seenQueries,
        array $columns,
        string $subject,
        string $category,
    ): void {
        $dateCol = $this->findFirstByType($columns, self::DATE_TYPES);
        $numericCol = $this->findBestNumericColumn($columns);

        if ($dateCol === null || $numericCol === null) {
            return;
        }

        $metric = $this->humanizeName($numericCol['name']);
        $query = "Show monthly {$metric} trend in {$subject}";
        $this->addIfUnique($suggestions, $seenQueries, [
            'label' => "Monthly {$metric} trend",
            'query' => $query,
            'category' => $category,
        ]);
    }

    private function addBreakdownSuggestion(
        array &$suggestions,
        array &$seenQueries,
        array $columns,
        string $subject,
        string $category,
    ): void {
        $enumCol = $this->findEnumColumn($columns);
        if ($enumCol === null) {
            return;
        }

        $colName = $this->humanizeName($enumCol['name']);
        $query = "Break down {$subject} by {$colName}";
        $this->addIfUnique($suggestions, $seenQueries, [
            'label' => "{$subject} breakdown by {$colName}",
            'query' => $query,
            'category' => $category,
        ]);
    }

    private function addAverageSuggestion(
        array &$suggestions,
        array &$seenQueries,
        array $columns,
        string $subject,
        string $category,
    ): void {
        $numericCol = $this->findBestNumericColumn($columns);
        if ($numericCol === null) {
            return;
        }

        $metric = $this->humanizeName($numericCol['name']);
        $query = "What is the average {$metric} in {$subject}?";
        $this->addIfUnique($suggestions, $seenQueries, [
            'label' => "Average {$metric} in {$subject}",
            'query' => $query,
            'category' => $category,
        ]);
    }

    private function addTopSuggestion(
        array &$suggestions,
        array &$seenQueries,
        array $columns,
        string $subject,
        string $category,
    ): void {
        $categoricalCol = $this->findBestCategoricalColumn($columns);
        $numericCol = $this->findBestNumericColumn($columns);

        if ($categoricalCol === null || $numericCol === null) {
            return;
        }

        $dimension = $this->humanizeName($categoricalCol['name']);
        $metric = $this->humanizeName($numericCol['name']);
        $query = "Which {$dimension} have the highest {$metric} in {$subject}?";
        $this->addIfUnique($suggestions, $seenQueries, [
            'label' => "Top {$dimension} by {$metric}",
            'query' => $query,
            'category' => $category,
        ]);
    }

    private function addIfUnique(
        array &$suggestions,
        array &$seenQueries,
        array $entry,
    ): void {
        if (count($suggestions) >= self::MAX_SUGGESTIONS) {
            return;
        }

        $norm = $this->normalizeQuery($entry['query']);
        if (isset($seenQueries[$norm])) {
            return;
        }

        $seenQueries[$norm] = true;
        $suggestions[] = $entry;
    }

    private function normalizeQuery(string $query): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $query)));
    }

    private function findFirstByType(array $columns, array $types): ?array
    {
        foreach ($columns as $col) {
            if (in_array(($col['type'] ?? ''), $types, true)) {
                return $col;
            }
        }
        return null;
    }

    private function findBestNumericColumn(array $columns): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($columns as $col) {
            $type = $col['type'] ?? '';
            $isNumeric = in_array($type, self::NUMERIC_TYPES, true);
            if (!$isNumeric) {
                continue;
            }

            $score = 0;
            $name = strtolower($col['name'] ?? '');

            if (
                str_contains($name, 'amount')
                || str_contains($name, 'total')
                || str_contains($name, 'revenue')
                || str_contains($name, 'price')
                || str_contains($name, 'cost')
            ) {
                $score += 3;
            } elseif (
                str_contains($name, 'rate')
                || str_contains($name, 'count')
                || str_contains($name, 'sum')
                || str_contains($name, 'avg')
                || str_contains($name, 'value')
            ) {
                $score += 2;
            }

            $pos = $col['ordinal_position'] ?? 999;
            $score += max(0, 20 - $pos);

            if ($best === null || $score > $bestScore) {
                $best = $col;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function findEnumColumn(array $columns): ?array
    {
        foreach ($columns as $col) {
            if (($col['type'] ?? '') === 'enum') {
                return $col;
            }
        }

        foreach ($columns as $col) {
            $name = strtolower($col['name'] ?? '');
            $type = $col['type'] ?? '';

            $isCategorical =
                $type === 'varchar'
                && (
                    str_contains($name, 'status')
                    || str_contains($name, 'type')
                    || str_contains($name, 'category')
                    || str_contains($name, 'state')
                    || str_contains($name, 'class')
                );

            if ($isCategorical) {
                return $col;
            }
        }

        return null;
    }

    private function findBestCategoricalColumn(array $columns): ?array
    {
        $candidates = [];

        foreach ($columns as $col) {
            $type = $col['type'] ?? '';

            if ($type === 'enum') {
                $candidates[] = ['col' => $col, 'score' => 10];
                continue;
            }

            $name = strtolower($col['name'] ?? '');
            $isString = in_array($type, ['varchar', 'char', 'text'], true);

            if (!$isString) {
                continue;
            }

            $score = 0;
            foreach (['name', 'type', 'category', 'group', 'status', 'city', 'region', 'country'] as $kw) {
                if (str_contains($name, $kw)) {
                    $score += 3;
                }
            }

            if ($score > 0) {
                $candidates[] = ['col' => $col, 'score' => $score];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        return $candidates[0]['col'];
    }

    private function fallbackSuggestions(): array
    {
        return [
            [
                'label' => 'What tables are available?',
                'query' => 'What tables are available?',
                'category' => 'Operations',
            ],
            [
                'label' => 'Show recent trends',
                'query' => 'Show me the recent trends in the data',
                'category' => 'Operations',
            ],
            [
                'label' => 'Summarize the main metrics',
                'query' => 'Summarize the main metrics across all data',
                'category' => 'Operations',
            ],
        ];
    }
}
