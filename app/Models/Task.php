<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description', 
        'is_completed',
        'due_date',
        'reminder_date',
        'priority',
        'estimated_hours'
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'due_date' => 'datetime',
        'reminder_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

        // Scopes for filtering
    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', today());
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->where('is_completed', false);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('due_date', '>', now())
                    ->orderBy('due_date', 'asc');
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}