<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('method', 50);

            $table->string('status', 30)
                ->default(PaymentStatus::Pending->value)
                ->index();

            $table->decimal('amount', 12, 2)->unsigned();

            $table->string('transaction_reference')
                ->nullable()
                ->index();

            $table->json('gateway_response')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
