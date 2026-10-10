<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Documents\DocumentStorage;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use App\Models\DetailingInspectionPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/** A before or after photo: an image, stored like any document (10 MB). */
final class InspectionPhotoRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.intdiv(DocumentStorage::MAX_BYTES, 1024), 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif'],
            'phase' => ['required', 'string', Rule::in(DetailingInspectionPhoto::PHASES)],
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function photo(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Validated above.');
    }

    public function phase(): string
    {
        return Input::string($this->validated('phase'));
    }

    public function caption(): ?string
    {
        return Input::text($this->validated('caption'));
    }
}
