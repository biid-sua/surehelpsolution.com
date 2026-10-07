<?php

namespace App\Services\Customers;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Phone;
use Illuminate\Support\Str;

/**
 * Customer import from a spreadsheet (CSV) (D53). Columns are matched to fields; each row must have a
 * name, phone or email. Existing customers (same phone, or the only one with that email) are skipped
 * or have only their empty fields filled. Consent can't be imported: it must be recorded where it was given.
 */
class CustomerImport
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const FIELDS = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'name' => 'Full name', 'company' => 'Company',
        'phone' => 'Phone', 'email' => 'Email', 'address_line1' => 'Street address', 'address_line2' => 'Unit',
        'city' => 'City', 'state' => 'State', 'postal_code' => 'ZIP / postal code', 'notes' => 'Notes', 'tags' => 'Tags (comma separated)',
    ];

    /** Header words that suggest a field. */
    private const HINTS = [
        'first_name' => ['first name', 'firstname', 'first', 'given name'],
        'last_name' => ['last name', 'lastname', 'surname', 'last', 'family name'],
        'name' => ['name', 'full name', 'customer', 'customer name', 'contact', 'contact name'],
        'company' => ['company', 'business', 'organization', 'organisation'],
        'phone' => ['phone', 'phone number', 'mobile', 'cell', 'telephone', 'tel'],
        'email' => ['email', 'e mail', 'email address', 'e mail address'],
        'address_line1' => ['address', 'street', 'address 1', 'address line 1', 'street address'],
        'address_line2' => ['address 2', 'address line 2', 'unit', 'apt', 'suite'],
        'city' => ['city', 'town'],
        'state' => ['state', 'province', 'region'],
        'postal_code' => ['zip', 'zip code', 'postal code', 'postcode'],
        'notes' => ['notes', 'note', 'comments'],
        'tags' => ['tags', 'tag', 'labels'],
    ];

    public function __construct(private readonly MatchOrCreateCustomer $match, private readonly Audit $audit) {}

    /**
     * @return array{headers: list<string>, rows: array<int, list<string>>} rows keyed by their line in the file
     *
     * @throws \InvalidArgumentException when the file can't be read as a table
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \InvalidArgumentException('The file couldn\'t be opened.');
        }
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        rewind($handle);

        $headers = null;
        $rows = [];
        $line = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if ($headers === null) {
                $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);   // spreadsheet byte-order mark
                $headers = $row;

                continue;
            }
            if (implode('', $row) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new \InvalidArgumentException('The file has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.');
            }
            $rows[$line] = $row;
        }
        fclose($handle);

        if (! $headers || $rows === []) {
            throw new \InvalidArgumentException('The file needs a header row and at least one customer.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Column index => field key ('' = ignore), guessed from the headers.
     *
     * @param  list<string>  $headers
     * @return array<int, string>
     */
    public function suggest(array $headers): array
    {
        $mapping = [];
        $used = [];
        foreach ($headers as $i => $header) {
            $h = Str::of($header)->lower()->replaceMatches('/[_\-.]+/', ' ')->squish()->toString();
            $mapping[$i] = '';
            foreach (self::HINTS as $field => $words) {
                if (! isset($used[$field]) && in_array($h, $words, true)) {
                    $mapping[$i] = $field;
                    $used[$field] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<int, list<string>>  $rows  keyed by line in the file
     * @param  array<int, string>  $mapping  column index => field
     * @return array{created: int, updated: int, skipped: int, problems: list<string>}
     */
    public function import(Organization $organization, array $rows, array $mapping, bool $fillExisting, User $actor): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'problems' => []];
        $problem = function (int $line, string $why) use (&$result) {
            $result['skipped']++;
            if (count($result['problems']) < 20) {
                $result['problems'][] = "Row {$line}: {$why}";
            }
        };

        foreach ($rows as $line => $row) {
            $v = [];
            foreach ($mapping as $i => $field) {
                if ($field !== '' && isset(self::FIELDS[$field])) {
                    $v[$field] = mb_substr(trim((string) ($row[$i] ?? '')), 0, $field === 'notes' ? 5000 : 255);
                }
            }
            if (($v['name'] ?? '') !== '' && ($v['first_name'] ?? '') === '' && ($v['last_name'] ?? '') === '') {
                [$v['first_name'], $v['last_name']] = array_pad(explode(' ', $v['name'], 2), 2, '');
            }
            unset($v['name']);
            $v = array_filter($v, fn ($x) => $x !== '');

            if (! isset($v['first_name']) && ! isset($v['last_name']) && ! isset($v['company']) && ! isset($v['phone']) && ! isset($v['email'])) {
                $problem($line, 'no name, phone or email.');

                continue;
            }
            if (isset($v['email']) && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
                $problem($line, '"'.Str::limit($v['email'], 40).'" isn\'t an email address.');

                continue;
            }
            if (isset($v['phone']) && Phone::normalize($v['phone']) === null) {
                $problem($line, '"'.Str::limit($v['phone'], 30).'" isn\'t a phone number we recognise.');

                continue;
            }

            $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($v['tags'] ?? '')))));
            unset($v['tags']);
            $existing = $this->match->lookup($organization, $v['phone'] ?? null, $v['email'] ?? null)['customer'] ?? null;

            if ($existing) {
                if (! $fillExisting) {
                    $problem($line, 'already a customer ('.$existing->fullName().').');

                    continue;
                }
                $empty = array_filter($v, fn ($value, $field) => blank($existing->{$field}), ARRAY_FILTER_USE_BOTH);
                $existing->fill($empty)->save();
                $this->tag($organization, $existing, $tags);
                $result['updated']++;

                continue;
            }

            $customer = Customer::create($v + ['organization_id' => $organization->id, 'source' => 'import']);
            $this->tag($organization, $customer, $tags);
            $result['created']++;
        }

        $this->audit->record('customers.imported', null, new: ['created' => $result['created'], 'updated' => $result['updated'], 'skipped' => $result['skipped']],
            organization: $organization, actor: $actor, label: 'Customer import');

        return $result;
    }

    /** @param  list<string>  $names */
    private function tag(Organization $organization, Customer $customer, array $names): void
    {
        $ids = [];
        foreach (array_slice($names, 0, 10) as $name) {
            $ids[] = Tag::firstOrCreate(['organization_id' => $organization->id, 'name' => mb_substr($name, 0, 50)])->id;
        }
        if ($ids) {
            $customer->tags()->syncWithoutDetaching($ids);
        }
    }
}
