<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PilotCheck extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_current' => 'boolean', 'issued_on' => 'date:Y-m-d', 'valid_from' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d'];
    public function user() { return $this->belongsTo(User::class); }
}
