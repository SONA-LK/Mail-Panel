<?php

namespace App\Services;

use App\Jobs\ProcessBulkRow;
use App\Models\BulkBatch;
use App\Models\BulkRow;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class BulkImportService
{
    public const COLUMNS = ['type', 'address', 'quota_mb', 'password', 'forward_to'];

    public function __construct(private ForwardingGuard $guard)
    {
    }

    public static function template(): string
    {
        return implode(',', self::COLUMNS)."\n"
            ."mailbox,anna@example.com,1024,,\n"
            ."forwarder,info@example.com,,,anna@example.com;sales@example.com\n";
    }

    /** Parse an uploaded CSV into bulk_rows. Nothing is sent to cPanel. */
    public function parse(User $user, UploadedFile $file): BulkBatch
    {
        $max = config('cpanel.csv_max_rows');
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if ($header) {
            $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF"); // strip a UTF-8 BOM from Excel
        }
        if (array_map(fn ($h) => strtolower(trim((string) $h)), $header ?: []) !== self::COLUMNS) {
            fclose($handle);
            throw new InvalidArgumentException('The header row must be exactly: '.implode(',', self::COLUMNS));
        }

        $rows = [];
        $n = 1;
        while (($cols = fgetcsv($handle)) !== false) {
            $n++;
            if ($cols === [null] || count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= $max) {
                fclose($handle);
                throw new InvalidArgumentException("The file has more than $max rows. Split it into smaller files.");
            }
            $cols = array_pad(array_map(fn ($c) => trim((string) $c), $cols), count(self::COLUMNS), '');
            $rows[] = ['n' => $n, 'data' => array_combine(self::COLUMNS, array_slice($cols, 0, count(self::COLUMNS)))];
        }
        fclose($handle);

        if (! $rows) {
            throw new InvalidArgumentException('The file has no data rows.');
        }

        $batch = BulkBatch::create([
            'user_id' => $user->id, 'original_name' => $file->getClientOriginalName(),
            'status' => 'parsed', 'total_rows' => count($rows),
        ]);

        foreach ($rows as $r) {
            BulkRow::create(['batch_id' => $batch->id, 'row_number' => $r['n'], 'payload' => $r['data'], 'status' => 'pending']);
        }

        return $batch;
    }

    /** Dry-run: validate every row against policy and the cache. Makes no change in cPanel. */
    public function validate(BulkBatch $batch): BulkBatch
    {
        $user = $batch->user;
        $seenMailboxes = [];
        $edges = [];

        foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
            $p = $row->payload;
            $errors = [];
            $warnings = [];
            $normalized = $p;

            $type = strtolower($p['type'] ?? '');
            $address = AddressRules::normalize($p['address'] ?? '');
            $normalized['type'] = $type;
            $normalized['address'] = $address;
            $domain = null;

            if (! in_array($type, ['mailbox', 'forwarder'], true)) {
                $errors[] = "Unknown type '{$p['type']}' (use mailbox or forwarder).";
            } elseif (! AddressRules::isEmail($address)) {
                $errors[] = "'{$p['address']}' is not a valid address.";
            } elseif (! ($domain = Domain::forAddress($address))) {
                $errors[] = 'The domain is not managed by this panel.';
            } elseif (! $user->canManage($domain)) {
                $errors[] = 'You do not have permission to manage this domain.';
            }

            if ($domain && ! $errors) {
                [$local] = AddressRules::split($address);

                if ($type === 'mailbox') {
                    $this->validateMailbox($p, $domain, $local, $address, $seenMailboxes, $normalized, $errors);
                } else {
                    $this->validateForwarder($p, $domain, $address, $edges, $normalized, $errors, $warnings);
                }
            }

            if ($errors) {
                $normalized['password'] = ''; // rows that will never run must not keep a password
            }

            $row->update([
                'payload' => $normalized,
                'status' => $errors ? 'invalid' : 'valid',
                'error_message' => $errors ? implode(' ', $errors) : null,
                'warnings' => $warnings ? implode(' ', $warnings) : null,
            ]);
        }

        $batch->update(['status' => 'validated']);

        return $batch;
    }

    private function validateMailbox(array $p, Domain $domain, string $local, string $address, array &$seen, array &$normalized, array &$errors): void
    {
        if ($msg = AddressRules::localPartError($local, $domain)) {
            $errors[] = $msg;
        }
        if (isset($seen[$address]) || Mailbox::where('address', $address)->exists()) {
            $errors[] = 'This mailbox already exists or is repeated in the file.';
        }
        $seen[$address] = true;

        $quota = $p['quota_mb'] === '' ? $domain->default_quota_mb : $p['quota_mb'];
        if (! ctype_digit((string) $quota) || (int) $quota < 1 || (int) $quota > config('cpanel.max_quota_mb')) {
            $errors[] = 'Quota must be a whole number between 1 and '.config('cpanel.max_quota_mb').' MB.';
        }
        $normalized['quota_mb'] = (int) $quota;

        if ($p['password'] !== '' && strlen($p['password']) < 8) {
            $errors[] = 'A given password must be at least 8 characters.';
        }
    }

    private function validateForwarder(array $p, Domain $domain, string $address, array &$edges, array &$normalized, array &$errors, array &$warnings): void
    {
        $dests = array_values(array_unique(array_filter(array_map(
            fn ($d) => AddressRules::normalize($d),
            explode(';', (string) $p['forward_to'])
        ))));
        $normalized['forward_to'] = $dests;

        if (! $dests) {
            $errors[] = 'forward_to is empty.';

            return;
        }

        $check = $this->guard->check($domain, $address, $dests, $edges);
        $errors = array_merge($errors, $check['errors']);

        if ($check['external']) {
            $warnings[] = 'External destination: '.implode(', ', $check['external']).'.';
        }
        if (! $check['errors']) {
            $edges[$address] = array_merge($edges[$address] ?? [], $dests);
        }
    }

    public function hasWarnings(BulkBatch $batch): bool
    {
        return $batch->rows()->where('status', 'valid')->whereNotNull('warnings')->exists();
    }

    /** Queue the valid rows. Cron drains the queue; invalid rows stay in the report. */
    public function confirm(BulkBatch $batch): int
    {
        if ($batch->status !== 'validated') {
            throw new InvalidArgumentException('This batch has already been confirmed or is not validated.');
        }

        $ids = $batch->rows()->where('status', 'valid')->pluck('id');
        $batch->rows()->whereIn('id', $ids)->update(['status' => 'queued']);

        if ($ids->isEmpty()) {
            $batch->update(['status' => 'done']);

            return 0;
        }

        $batch->update(['status' => 'queued']);
        Audit::record($batch->user, 'bulk.confirm', "batch #{$batch->id}", null, ['rows' => $ids->count(), 'file' => $batch->original_name]);

        foreach ($ids as $id) {
            ProcessBulkRow::dispatch($id);
        }

        return $ids->count();
    }

    /** Close the batch once no row is waiting. */
    public function refreshStatus(BulkBatch $batch): void
    {
        $batch->refresh();
        if ($batch->status !== 'queued' || $batch->rows()->where('status', 'queued')->exists()) {
            return;
        }

        $batch->update(['status' => $batch->failed_rows > 0 ? 'partial' : 'done']);
        Audit::record($batch->user, 'bulk.finish', "batch #{$batch->id}", null, [
            'done' => $batch->done_rows, 'failed' => $batch->failed_rows,
        ], $batch->failed_rows > 0 ? 'failure' : 'success');
    }

    /**
     * Build the result CSV. Generated passwords are included once and then wiped.
     */
    public function resultCsv(BulkBatch $batch): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['row', 'type', 'address', 'status', 'message', 'warnings', 'generated_password']);

        foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
            $p = $row->payload ?? [];
            fputcsv($out, [
                $row->row_number, $this->safe($p['type'] ?? ''), $this->safe($p['address'] ?? ''), $row->status,
                $this->safe((string) $row->error_message), $this->safe((string) $row->warnings), $row->result_secret,
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        if ($batch->rows()->whereNotNull('result_secret')->exists()) {
            $batch->rows()->whereNotNull('result_secret')->update(['result_secret' => null]);
            Audit::record($batch->user, 'bulk.download', "batch #{$batch->id}", null, ['passwords_wiped' => true]);
        }

        return $csv;
    }

    /** Stop spreadsheet formula injection in exported text. */
    private function safe(string $v): string
    {
        return \App\Support\Csv::safe($v);
    }
}
