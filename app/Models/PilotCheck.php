<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PilotCheck extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_current' => 'boolean', 'issued_on' => 'date:Y-m-d', 'valid_from' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d'];
    public static function isSupportedPortalTitle(string $title): bool
    {
        preg_match('/\[([^\[\]]+)\]/u', $title, $match);
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $match[1] ?? $title)));
        $name = str_replace(['ё', '‑', '–'], ['е', '-', '-'], $name);
        return in_array($name, [
            'квалификационная проверка',
            'подтверждение полномочий пи',
            'подтверждение полномочий пилота-экзаменатора',
        ], true);
    }
    public function isVisibleCheck(): bool
    {
        return $this->origin === 'manual' || static::isSupportedPortalTitle($this->title);
    }
    public function user() { return $this->belongsTo(User::class); }
}
