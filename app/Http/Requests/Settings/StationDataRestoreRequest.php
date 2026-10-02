<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StationDataRestoreRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
            // The file's content is checked in StationBackupRestorer (SQLite header, integrity,
            // station tables), so the extension is only a first filter: 20 MB matches the
            // server's upload limit.
            'backup' => ['required', 'file', 'max:20480', 'extensions:sqlite,db'],
        ];
    }
}
