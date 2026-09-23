<?php

namespace Tests\Feature\Accounting;

use App\Services\Banking\IntegerMoneyParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankStatementImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_confirm_and_retry_do_not_duplicate_transactions(): void
    {
        $context = $this->stage6BankingContext();
        $csv = "date,description,reference,direction,amount,balance,counterparty\n2026-09-20,Customer receipt,RCPT-1,credit,\"1,250.50\",\"5,250.50\",Customer\n";
        $payload = ['financial_account_id' => $context['bank']->id, 'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv), 'closing_balance' => 525050];
        $headers = $this->headers($context['company']->id, 'import-one');
        $preview = $this->post('/api/v1/banking/statement-imports/preview', $payload, $headers)->assertCreated()->assertJsonPath('row_count', 1);
        $importId = $preview->json('id');
        $this->postJson("/api/v1/banking/statement-imports/{$importId}/confirm", [], $headers)->assertOk()->assertJsonPath('imported_count', 1);
        $this->postJson("/api/v1/banking/statement-imports/{$importId}/confirm", [], $headers)->assertOk();

        $this->assertDatabaseCount('bank_transactions', 1);
        $this->assertDatabaseHas('bank_transactions', ['amount' => 125050, 'direction' => 'credit', 'running_balance' => 525050]);
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_duplicate_file_returns_original_import_and_invalid_rows_cannot_confirm(): void
    {
        $context = $this->stage6BankingContext();
        $csv = "date,description,direction,amount\n2026-09-20,Bad amount,credit,nope\n";
        $first = $this->post('/api/v1/banking/statement-imports/preview', ['financial_account_id' => $context['bank']->id, 'file' => UploadedFile::fake()->createWithContent('bad.csv', $csv)], $this->headers($context['company']->id, 'bad-one'))->assertCreated();
        $second = $this->post('/api/v1/banking/statement-imports/preview', ['financial_account_id' => $context['bank']->id, 'file' => UploadedFile::fake()->createWithContent('bad.csv', $csv)], $this->headers($context['company']->id, 'bad-two'))->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->postJson('/api/v1/banking/statement-imports/'.$first->json('id').'/confirm', [], $this->headers($context['company']->id, 'bad-one'))->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_integer_money_parser_rejects_float_and_malformed_precision(): void
    {
        $parser = app(IntegerMoneyParser::class);
        $this->assertSame(123456, $parser->parse('1,234.56'));
        $this->assertSame(-1250, $parser->parse('(12.50)'));

        $this->expectException(ValidationException::class);
        $parser->parse(12.34);
    }

    public function test_malformed_date_is_reported_in_preview_and_duplicate_transaction_is_skipped_across_files(): void
    {
        $context = $this->stage6BankingContext();
        $invalid = "date,description,direction,amount\nnot-a-date,Receipt,credit,10.00\n";
        $invalidPreview = $this->post('/api/v1/banking/statement-imports/preview', ['financial_account_id' => $context['bank']->id, 'file' => UploadedFile::fake()->createWithContent('invalid-date.csv', $invalid)], $this->headers($context['company']->id, 'invalid-date'))->assertCreated();
        $this->assertNotEmpty($invalidPreview->json('preview_rows.0.errors'));

        $csv = "date,description,reference,direction,amount\n2026-09-20,Same receipt,REF-1,credit,10.00\n";
        $first = $this->post('/api/v1/banking/statement-imports/preview', ['financial_account_id' => $context['bank']->id, 'file' => UploadedFile::fake()->createWithContent('one.csv', $csv)], $this->headers($context['company']->id, 'file-one'))->assertCreated();
        $this->postJson('/api/v1/banking/statement-imports/'.$first->json('id').'/confirm', [], $this->headers($context['company']->id, 'file-one'))->assertOk();
        $secondCsv = $csv."\n";
        $second = $this->post('/api/v1/banking/statement-imports/preview', ['financial_account_id' => $context['bank']->id, 'file' => UploadedFile::fake()->createWithContent('two.csv', $secondCsv)], $this->headers($context['company']->id, 'file-two'))->assertCreated();
        $this->postJson('/api/v1/banking/statement-imports/'.$second->json('id').'/confirm', [], $this->headers($context['company']->id, 'file-two'))->assertOk()->assertJsonPath('duplicate_count', 1);
        $this->assertDatabaseCount('bank_transactions', 1);
    }

    public function test_money_parser_rejects_more_than_two_decimal_places(): void
    {
        $this->expectException(ValidationException::class);
        app(IntegerMoneyParser::class)->parse('10.001');
    }

    /** @return array<string, string> */
    private function headers(string $companyId, string $key): array
    {
        return ['X-Company-Id' => $companyId, 'Idempotency-Key' => $key, 'Accept' => 'application/json'];
    }
}
