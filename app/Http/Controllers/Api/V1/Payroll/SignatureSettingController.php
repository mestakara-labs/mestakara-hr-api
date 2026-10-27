<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Enums\SignatureDocument;
use App\Enums\SignatureOwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\SignatureSettingRequest;
use App\Models\Division;
use App\Models\PayrollGroup;
use App\Models\SignatureSetting;
use App\Services\Payroll\SignatureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Penanda tangan Daftar Upah & SPKL: susunan Umum, per Kelompok Daftar Upah, dan per Bidang.
 *
 *   GET /signature-settings/general                tempat + susunan Umum
 *   PUT /signature-settings/general                ubah (tempat & kedua susunan wajib)
 *   GET /signature-settings/payroll-groups         daftar kelompok + ringkasan susunannya (null = pakai Umum)
 *   GET /signature-settings/payroll-groups/{id}    detail susunan satu kelompok
 *   PUT /signature-settings/payroll-groups/{id}    ubah (null = pakai Umum)
 *   GET/PUT /signature-settings/divisions[/{id}]   sama, per Bidang
 */
class SignatureSettingController extends Controller
{
    public function __construct(private SignatureService $signatures) {}

    /**
     * Penanda tangan Umum
     */
    public function general(): JsonResponse
    {
        return ApiResponse::success($this->generalArray(), 'Penanda tangan umum.');
    }

    /**
     * Ubah penanda tangan Umum
     */
    public function updateGeneral(SignatureSettingRequest $request): JsonResponse
    {
        $this->save($request, SignatureOwnerType::General, null);

        return ApiResponse::success($this->generalArray(), 'Penanda tangan umum berhasil disimpan.');
    }

    /**
     * Daftar penanda tangan per Kelompok Daftar Upah
     */
    public function payrollGroups(): JsonResponse
    {
        $groups = PayrollGroup::query()->orderBy('sort_order')->orderBy('name')->get();

        return ApiResponse::success(
            $this->listArray($groups->all(), SignatureOwnerType::PayrollGroup, fn (PayrollGroup $group) => $group->sort_order),
            'Daftar penanda tangan per kelompok daftar upah.',
        );
    }

    /**
     * Penanda tangan satu Kelompok Daftar Upah
     */
    public function payrollGroup(PayrollGroup $payrollGroup): JsonResponse
    {
        return ApiResponse::success(
            $this->ownerArray($payrollGroup->id, $payrollGroup->name, SignatureOwnerType::PayrollGroup),
            'Penanda tangan kelompok daftar upah.',
        );
    }

    /**
     * Ubah penanda tangan satu Kelompok Daftar Upah
     */
    public function updatePayrollGroup(SignatureSettingRequest $request, PayrollGroup $payrollGroup): JsonResponse
    {
        $this->save($request, SignatureOwnerType::PayrollGroup, $payrollGroup->id);

        return ApiResponse::success(
            $this->ownerArray($payrollGroup->id, $payrollGroup->name, SignatureOwnerType::PayrollGroup),
            'Penanda tangan kelompok daftar upah berhasil disimpan.',
        );
    }

    /**
     * Daftar penanda tangan per Bidang
     */
    public function divisions(): JsonResponse
    {
        $divisions = Division::query()->orderBy('name')->get();

        return ApiResponse::success(
            $this->listArray($divisions->all(), SignatureOwnerType::Division, fn () => null),
            'Daftar penanda tangan per bidang.',
        );
    }

    /**
     * Penanda tangan satu Bidang
     */
    public function division(Division $division): JsonResponse
    {
        return ApiResponse::success(
            $this->ownerArray($division->id, $division->name, SignatureOwnerType::Division),
            'Penanda tangan bidang.',
        );
    }

    /**
     * Ubah penanda tangan satu Bidang
     */
    public function updateDivision(SignatureSettingRequest $request, Division $division): JsonResponse
    {
        $this->save($request, SignatureOwnerType::Division, $division->id);

        return ApiResponse::success(
            $this->ownerArray($division->id, $division->name, SignatureOwnerType::Division),
            'Penanda tangan bidang berhasil disimpan.',
        );
    }

    private function save(SignatureSettingRequest $request, SignatureOwnerType $ownerType, ?string $ownerId): void
    {
        $this->signatures->save(
            $ownerType,
            $ownerId,
            $request->validated('place'),
            $request->validated('payroll_sections'),
            $request->validated('spkl_sections'),
        );
    }

    /** Umum: { place, payroll_sections, spkl_sections, updated_at, updated_by }. Belum diatur = tempat bawaan & susunan kosong. */
    private function generalArray(): array
    {
        $setting = $this->signatures->find(SignatureOwnerType::General, null);

        return [
            'place' => $setting?->place ?? SignatureService::DEFAULT_PLACE,
            'payroll_sections' => $this->signatures->sectionsArray($setting, SignatureDocument::Payroll) ?? [],
            'spkl_sections' => $this->signatures->sectionsArray($setting, SignatureDocument::Spkl) ?? [],
            ...$this->updatedArray($setting),
        ];
    }

    /** Detail kelompok / bidang: { id, name, place, payroll_sections, spkl_sections, updated_at, updated_by } (null = pakai Umum). */
    private function ownerArray(string $id, string $name, SignatureOwnerType $ownerType): array
    {
        $setting = $this->signatures->find($ownerType, $id);

        return [
            'id' => $id,
            'name' => $name,
            'place' => $setting?->place,
            'payroll_sections' => $this->signatures->sectionsArray($setting, SignatureDocument::Payroll),
            'spkl_sections' => $this->signatures->sectionsArray($setting, SignatureDocument::Spkl),
            ...$this->updatedArray($setting),
        ];
    }

    /**
     * Daftar: [{ id, name, sort_order, place, payroll: {sections, signers} | null, spkl: {...} | null }].
     *
     * @param  list<PayrollGroup|Division>  $owners
     */
    private function listArray(array $owners, SignatureOwnerType $ownerType, callable $sortOrder): array
    {
        $settings = $this->signatures->allOf($ownerType);

        return array_map(function (PayrollGroup|Division $owner) use ($settings, $sortOrder) {
            $setting = $settings->get($owner->id);

            return [
                'id' => $owner->id,
                'name' => $owner->name,
                /** Bidang tidak punya urutan (null). */
                'sort_order' => $sortOrder($owner),
                'place' => $setting?->place,
                'payroll' => $this->signatures->counts($setting, SignatureDocument::Payroll),
                'spkl' => $this->signatures->counts($setting, SignatureDocument::Spkl),
            ];
        }, $owners);
    }

    /** @return array{updated_at: string|null, updated_by: string|null} */
    private function updatedArray(?SignatureSetting $setting): array
    {
        return [
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updater?->name,
        ];
    }
}
