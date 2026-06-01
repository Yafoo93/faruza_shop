<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'before',
        'after',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function record(Request $request, string $action, string $description, array $options = []): self
    {
        return self::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $options['subject_type'] ?? null,
            'subject_id' => $options['subject_id'] ?? null,
            'description' => $description,
            'before' => $options['before'] ?? null,
            'after' => $options['after'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
