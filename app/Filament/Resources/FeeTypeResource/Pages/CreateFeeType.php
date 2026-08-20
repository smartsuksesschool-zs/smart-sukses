<?php

namespace App\Filament\Resources\FeeTypeResource\Pages;

use App\Filament\Resources\FeeTypeResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * `school_id` tidak pernah diambil apa adanya dari payload: hanya Super Admin
 * yang boleh memilih cabang, peran School Level selalu terikat cabang akunnya.
 */
class CreateFeeType extends CreateRecord
{
    protected static string $resource = FeeTypeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $schoolId = FeeTypeResource::resolveSchoolId($data['school_id'] ?? null);

        if ($schoolId === null) {
            throw new \RuntimeException('Cabang sekolah belum ditentukan untuk jenis tagihan ini.');
        }

        $data['school_id'] = $schoolId;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
