<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Penanda tangan SPKL & Daftar Upah PINDAH dari Pengaturan Lembur (overtime_setting_signers) ke penanda tangan
 * fleksibel (signature_settings, ERD 3.6). Isian yang sudah diisi admin dipindahkan ke susunan UMUM
 * (setiap peran = satu bagian berisi satu orang manual), lalu tabel lama dihapus.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['Daftar Upah' => 'daftar_upah', 'SPKL' => 'spkl'];

    public function up(): void
    {
        $old = Schema::hasTable('overtime_setting_signers')
            ? DB::table('overtime_setting_signers')->whereNotNull('name')->where('name', '!=', '')->orderBy('sort_order')->get()
            : collect();

        if ($old->isNotEmpty() && ! DB::table('signature_settings')->where('owner_type', 'umum')->exists()) {
            $settingId = (string) Str::uuid7();
            DB::table('signature_settings')->insert([
                'id' => $settingId, 'owner_type' => 'umum', 'owner_id' => null, 'place' => 'Gunung Mas',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach (self::DOCUMENTS as $oldDocument => $document) {
                foreach ($old->where('document_type', $oldDocument)->values() as $index => $signer) {
                    $sectionId = (string) Str::uuid7();
                    DB::table('signature_sections')->insert([
                        'id' => $sectionId, 'signature_setting_id' => $settingId, 'document' => $document,
                        'sort_order' => $index + 1, 'label' => $signer->role_label,
                    ]);
                    DB::table('signature_section_signers')->insert([
                        'id' => (string) Str::uuid7(), 'signature_section_id' => $sectionId, 'sort_order' => 1,
                        'source' => 'manual', 'employee_id' => null, 'name' => $signer->name, 'position' => $signer->position,
                    ]);
                }
            }
        }

        Schema::dropIfExists('overtime_setting_signers');
    }

    public function down(): void
    {
        Schema::create('overtime_setting_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('overtime_setting_id')->constrained('overtime_settings')->cascadeOnDelete();
            $table->string('document_type', 20);
            $table->unsignedTinyInteger('sort_order');
            $table->string('role_label', 50);
            $table->string('position', 150);
            $table->string('name', 150)->nullable();
            $table->timestamps();

            $table->unique(['overtime_setting_id', 'document_type', 'sort_order'], 'overtime_setting_signers_unique');
        });
    }
};
