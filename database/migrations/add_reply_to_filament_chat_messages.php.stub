<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.2: a message may answer another message of the same conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $messages = config('filament-chat.tables.messages', 'chat_messages');

        Schema::table($messages, function (Blueprint $table) use ($messages): void {
            $table->foreignId('reply_to_id')->nullable()->after('reference_id')->constrained($messages)->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(config('filament-chat.tables.messages', 'chat_messages'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
