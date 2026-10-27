<?php

namespace App\Support;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Penyesuaian dokumentasi API otomatis (Scramble, halaman /docs/api).
 *
 * Scramble membaca route, FormRequest, dan Resource sendiri. Yang perlu disesuaikan
 * hanya bentuk respons ERROR bawaan (401, 422, ...), karena aplikasi ini mengubahnya
 * ke bentuk standar { success, message, data, errors? } di bootstrap/app.php.
 *
 * Dipasang di AppServiceProvider::boot().
 */
class ApiDocumentation
{
    /** Ganti isi semua respons error bersama (components.responses) dengan bentuk standar aplikasi. */
    public static function useStandardErrorResponses(OpenApi $openApi): void
    {
        foreach ($openApi->components->responses as $name => $response) {
            if (! $response instanceof Response) {
                continue;
            }

            $withFieldErrors = str_ends_with($name, 'ValidationException');

            $response->setContent('application/json', Schema::fromType(self::errorBody($withFieldErrors)));
        }
    }

    /**
     * Bentuk respons error standar:
     *   { "success": false, "message": "...", "data": null, "errors": { "isian": ["pesan"] } }
     * `errors` hanya ada pada error validasi (422).
     */
    private static function errorBody(bool $withFieldErrors): ObjectType
    {
        $body = (new ObjectType)
            ->addProperty('success', (new BooleanType)->example(false))
            ->addProperty('message', (new StringType)->setDescription('Pesan error yang bisa ditampilkan ke pengguna.'))
            ->addProperty('data', new NullType);

        $required = ['success', 'message', 'data'];

        if ($withFieldErrors) {
            $fieldMessages = (new ObjectType)
                ->setDescription('Pesan per isian, mis. { "name": ["Nama wajib diisi."] }.')
                ->additionalProperties((new ArrayType)->setItems(new StringType));

            $body->addProperty('errors', $fieldMessages);
            $required[] = 'errors';
        }

        return $body->setRequired($required);
    }
}
