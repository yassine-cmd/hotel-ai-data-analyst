<?php

namespace Tests\Unit\Services;

use App\Services\SuggestionService;
use Tests\TestCase;

class SuggestionServiceTest extends TestCase
{
    private SuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SuggestionService();
    }

    public function test_returns_suggestions_with_correct_shape(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 1000,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'key' => 'PRI', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $this->assertNotEmpty($suggestions);
        foreach ($suggestions as $s) {
            $this->assertArrayHasKey('label', $s);
            $this->assertArrayHasKey('query', $s);
            $this->assertArrayHasKey('category', $s);
            $this->assertIsString($s['label']);
            $this->assertIsString($s['query']);
            $this->assertIsString($s['category']);
        }
    }

    public function test_skips_sensitive_tables(): void
    {
        $schema = $this->makeSchema([
            'employees' => [
                'row_count' => 500,
                'description' => 'Employee records.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                ],
            ],
        ]);
        $schema['sensitive_tables'] = ['employees'];

        $suggestions = $this->service->generate($schema);

        $this->assertCount(3, $suggestions);
        $this->assertSame('What tables are available?', $suggestions[0]['label']);
    }

    public function test_skips_sensitive_columns(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 100,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'ssn', 'type' => 'varchar', 'is_sensitive' => true],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $this->assertNotEmpty($suggestions);
        $queries = array_column($suggestions, 'query');
        $combined = implode(' ', $queries);
        $this->assertStringNotContainsString('ssn', strtolower($combined));
    }

    public function test_skips_sensitive_columns_via_global_blocklist(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 100,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'salary', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);
        $schema['sensitive_columns']['*'][] = 'salary';

        $suggestions = $this->service->generate($schema);

        $this->assertNotEmpty($suggestions);
        $queries = array_column($suggestions, 'query');
        $combined = implode(' ', $queries);
        $this->assertStringNotContainsString('salary', strtolower($combined));
    }

    public function test_generates_trend_when_date_and_numeric_columns_exist(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 500,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $labels = array_column($suggestions, 'label');
        $this->assertNotEmpty(array_filter($labels, fn($l) => stripos($l, 'trend') !== false));
    }

    public function test_generates_breakdown_when_enum_column_exists(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 500,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'status', 'type' => 'enum', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $labels = array_column($suggestions, 'label');
        $this->assertNotEmpty(array_filter($labels, fn($l) => stripos($l, 'breakdown') !== false));
    }

    public function test_caps_results_at_eight(): void
    {
        $tables = [];
        for ($i = 0; $i < 10; $i++) {
            $tables["table_{$i}"] = [
                'row_count' => 1000,
                'description' => "Table {$i}.",
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'date_created', 'type' => 'date', 'is_sensitive' => false],
                ],
            ];
        }
        $schema = $this->makeSchema($tables);

        $suggestions = $this->service->generate($schema);

        $this->assertCount(8, $suggestions);
    }

    public function test_deduplicates_repeated_query_text(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 100,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
            'bookings' => [
                'row_count' => 100,
                'description' => 'Bookings table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $queries = array_column($suggestions, 'query');
        $this->assertCount(count(array_unique($queries)), $queries);
    }

    public function test_returns_fallback_when_no_useful_metadata(): void
    {
        $schema = $this->makeSchema([]);

        $suggestions = $this->service->generate($schema);

        $this->assertCount(3, $suggestions);
        $this->assertSame('What tables are available?', $suggestions[0]['label']);
        $this->assertSame('Operations', $suggestions[0]['category']);
    }

    public function test_treats_row_count_null_as_eligible(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => null,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $this->assertNotEmpty($suggestions);
        $this->assertGreaterThan(0, count($suggestions));
    }

    public function test_skips_row_count_zero(): void
    {
        $schema = $this->makeSchema([
            'empty_table' => [
                'row_count' => 0,
                'description' => 'Empty table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $this->assertCount(3, $suggestions);
        $this->assertSame('What tables are available?', $suggestions[0]['label']);
    }

    public function test_does_not_fail_on_sparse_schema(): void
    {
        $schema = [
            'tables' => [],
            'sensitive_tables' => [],
            'sensitive_columns' => ['*' => []],
        ];

        $suggestions = $this->service->generate($schema);

        $this->assertCount(3, $suggestions);
    }

    public function test_table_with_only_sensitive_columns_skipped(): void
    {
        $schema = $this->makeSchema([
            'sensitive_data' => [
                'row_count' => 100,
                'description' => 'Sensitive data table.',
                'columns' => [
                    ['name' => 'ssn', 'type' => 'varchar', 'is_sensitive' => true],
                    ['name' => 'salary', 'type' => 'decimal', 'is_sensitive' => true],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $this->assertCount(3, $suggestions);
    }

    public function test_assigns_category_by_french_keywords(): void
    {
        $schema = $this->makeSchema([
            'chiffre_affaires' => [
                'row_count' => 500,
                'description' => 'Chiffre affaires.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'montant', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'date_facture', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
            'taux_occupation' => [
                'row_count' => 500,
                'description' => "Taux d'occupation.",
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'taux', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'date_sejour', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $this->assertNotEmpty($suggestions);
        $categories = array_unique(array_column($suggestions, 'category'));
        $this->assertContains('Revenue & Financial', $categories);
        $this->assertContains('Occupancy & Bookings', $categories);
    }

    public function test_assigns_category_by_keywords(): void
    {
        $schema = $this->makeSchema([
            'revenue' => [
                'row_count' => 500,
                'description' => 'Revenue table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'transaction_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
            'occupancy' => [
                'row_count' => 500,
                'description' => 'Occupancy table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'rate', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'record_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
            'audit_log' => [
                'row_count' => 200,
                'description' => 'Audit log.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'event', 'type' => 'varchar', 'is_sensitive' => false],
                    ['name' => 'logged_at', 'type' => 'datetime', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $categories = array_unique(array_column($suggestions, 'category'));
        $this->assertContains('Revenue & Financial', $categories);
        $this->assertContains('Occupancy & Bookings', $categories);
        $this->assertContains('Operations', $categories);
    }

    public function test_uses_description_for_subject(): void
    {
        $schema = $this->makeSchema([
            'fin_table' => [
                'row_count' => 100,
                'description' => 'Revenue and payments table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'created_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $labels = array_column($suggestions, 'label');
        $hasRevenueSubject = !empty(array_filter($labels, fn($l) => stripos($l, 'Revenue') !== false));
        $this->assertTrue($hasRevenueSubject);
    }

    public function test_generates_top_suggestion(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 500,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'hotel_name', 'type' => 'varchar', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $labels = array_column($suggestions, 'label');
        $this->assertNotEmpty(array_filter($labels, fn($l) => stripos($l, 'Top') !== false));
    }

    public function test_generates_average_suggestion(): void
    {
        $schema = $this->makeSchema([
            'reservations' => [
                'row_count' => 500,
                'description' => 'Reservations table.',
                'columns' => [
                    ['name' => 'id', 'type' => 'int', 'is_sensitive' => false],
                    ['name' => 'total_amount', 'type' => 'decimal', 'is_sensitive' => false],
                    ['name' => 'arrival_date', 'type' => 'date', 'is_sensitive' => false],
                ],
            ],
        ]);

        $suggestions = $this->service->generate($schema);

        $labels = array_column($suggestions, 'label');
        $this->assertNotEmpty(array_filter($labels, fn($l) => stripos($l, 'Average') !== false));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeSchema(array $tables): array
    {
        $result = [];
        foreach ($tables as $name => $def) {
            $result[$name] = [
                'row_count' => $def['row_count'] ?? null,
                'description' => $def['description'] ?? null,
                'is_sensitive' => $def['is_sensitive'] ?? false,
                'foreign_keys' => $def['foreign_keys'] ?? [],
                'virtual_foreign_keys' => $def['virtual_foreign_keys'] ?? [],
                'columns' => $def['columns'] ?? [],
            ];
        }

        return [
            'tables' => $result,
            'sensitive_tables' => [],
            'sensitive_columns' => ['*' => []],
        ];
    }
}
