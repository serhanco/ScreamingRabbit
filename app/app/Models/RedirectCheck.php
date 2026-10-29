<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site migration check: a list of old URLs (optionally with the new URL each should land on), each followed
 * through its redirects to see where it ends up.
 */
class RedirectCheck extends Model
{
    public const MAX_URLS = 1000;

    public const VERDICTS = [
        'ok' => 'Doğru yönleniyor',
        'same' => 'Yönlenmiyor, sayfa açılıyor',
        'chain' => 'Zincirleme yönlendirme',
        'temporary' => 'Geçici yönlendirme (302/307)',
        'wrong_target' => 'Beklenen adrese gitmiyor',
        'broken' => 'Hata sayfasında bitiyor',
        'loop' => 'Döngü / çok uzun zincir',
        'error' => 'Yanıt yok',
    ];

    public const PROBLEMS = ['chain', 'temporary', 'wrong_target', 'broken', 'loop', 'error'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rows' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One URL per line, or "old new" separated by spaces, a tab, a comma or a semicolon (pasted from a spreadsheet).
     *
     * @return array<int, array{url: string, expected: ?string, state: string}>
     */
    public static function parseList(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $parts = array_values(array_filter(preg_split('/[\s,;]+/', trim($line)), fn (string $part) => preg_match('#^https?://#i', $part)));
            if ($parts !== [] && count($rows) < self::MAX_URLS) {
                $rows[$parts[0]] ??= ['url' => $parts[0], 'expected' => $parts[1] ?? null, 'state' => 'pending'];
            }
        }

        return array_values($rows);
    }

    /**
     * @return array<string, int> verdict => count, for the done rows
     */
    public function summary(): array
    {
        return collect($this->rows)->where('state', 'done')->countBy('verdict')->all();
    }

    public function progress(): string
    {
        $done = collect($this->rows)->where('state', 'done')->count();

        return $done.' / '.count($this->rows);
    }
}
