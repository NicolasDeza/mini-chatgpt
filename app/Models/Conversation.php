<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'title', 'model', 'is_temporary'];

    /**
     * Une conversation appartient à un utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Une conversation a plusieurs messages
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Scope pour récupérer les conversations les plus récentes en premier
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('updated_at', 'desc');
    }
}
