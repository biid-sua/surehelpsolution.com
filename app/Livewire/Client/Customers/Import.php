<?php

namespace App\Livewire\Client\Customers;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Services\Customers\CustomerImport;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Import customers from a spreadsheet (D53): upload a CSV, match its columns, check a preview, import.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Import customers')]
class Import extends Component
{
    use ScopedToOrganization;
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** @var list<string> */
    public array $headers = [];

    /** @var list<list<string>> first rows, for the preview */
    public array $preview = [];

    public int $rowCount = 0;

    /** @var array<int, string> column index => field */
    public array $mapping = [];

    public bool $fillExisting = false;

    /** @var array{created: int, updated: int, skipped: int, problems: list<string>}|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize('customers.create', $this->organization());
    }

    public function updatedFile(CustomerImport $import): void
    {
        $this->authorize('customers.create', $this->organization());
        $this->reset('headers', 'preview', 'rowCount', 'mapping', 'result');
        $this->validate(['file' => ['required', 'file', 'max:'.(CustomerImport::MAX_BYTES / 1024), 'mimes:csv,txt']], attributes: ['file' => 'file']);

        try {
            $table = $import->read($this->file->getRealPath());
        } catch (\InvalidArgumentException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }
        $this->headers = $table['headers'];
        $this->preview = array_slice($table['rows'], 0, 5);   // renumbered from 0
        $this->rowCount = count($table['rows']);
        $this->mapping = $import->suggest($this->headers);
    }

    public function import(CustomerImport $import): void
    {
        $organization = $this->organization();
        $this->authorize('customers.create', $organization);
        if (! $this->file) {
            $this->addError('file', 'Choose a file first.');

            return;
        }
        $this->validate(['mapping.*' => ['nullable', Rule::in(['', ...array_keys(CustomerImport::FIELDS)])]]);
        $chosen = array_filter($this->mapping);
        if (count($chosen) !== count(array_unique($chosen))) {
            $this->addError('mapping', 'Each field can come from only one column.');

            return;
        }
        if (! array_intersect($chosen, ['first_name', 'last_name', 'name', 'company', 'phone', 'email'])) {
            $this->addError('mapping', 'Match at least a name, phone or email column.');

            return;
        }

        try {
            $table = $import->read($this->file->getRealPath());
        } catch (\InvalidArgumentException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }
        $this->result = $import->import($organization, $table['rows'], array_map('strval', $this->mapping), $this->fillExisting, auth()->user());
        $this->reset('file', 'headers', 'preview', 'rowCount', 'mapping');
    }

    public function render(): View
    {
        return view('livewire.client.customers.import', ['fields' => CustomerImport::FIELDS, 'maxRows' => CustomerImport::MAX_ROWS]);
    }
}
