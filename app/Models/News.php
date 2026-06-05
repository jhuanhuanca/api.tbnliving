<?php

namespace App\Models;

use App\Support\NewsImageStorage;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 'news';

    protected $fillable = [
        'title',
        'summary',
        'body',
        'image_path',
        'image_mime',
        'image_original_name',
        'estado',
        'published_at',
    ];

    protected $hidden = [
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'body' => $this->body,
            'estado' => $this->estado,
            'published_at' => $this->published_at?->toIso8601String(),
            'has_image' => NewsImageStorage::existsFor($this),
            'image_url' => NewsImageStorage::existsFor($this)
                ? url('/api/v1/public/news/'.$this->id.'/image')
                : null,
        ];
    }
}
