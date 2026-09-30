<?php

use App\Domains\Billing\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('office_id')->index()->constrained('offices')->restrictOnDelete();
            $table->ulid('client_party_id');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('IDR');
            $table->date('received_on');
            $table->string('method_code', 30);
            $table->ulid('created_by');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign(['client_party_id', 'office_id'], 'client_receipts_party_office_foreign')
                ->references(['id', 'office_id'])->on('parties')->restrictOnDelete();

            foreach ([
                'created_by' => 'client_receipts_created_by_office_foreign',
                'updated_by' => 'client_receipts_updated_by_office_foreign',
            ] as $column => $name) {
                $table->foreign([$column, 'office_id'], $name)
                    ->references(['id', 'office_id'])->on('users')->restrictOnDelete();
            }

            $table->index(['office_id', 'received_on'], 'client_receipts_office_received_index');
            $table->index(['office_id', 'client_party_id'], 'client_receipts_office_client_index');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $methods = implode("', '", PaymentMethod::values());
            $connection->statement(
                'ALTER TABLE client_receipts ADD CONSTRAINT client_receipts_amount_positive_check CHECK (amount > 0)'
            );
            $connection->statement(
                "ALTER TABLE client_receipts ADD CONSTRAINT client_receipts_method_check CHECK (method_code IN ('{$methods}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_receipts');
    }
};
