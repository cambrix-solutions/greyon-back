<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A CMS page (Privacy, Terms, Booking terms, FAQ...). `blocks` is a list of
 * typed content blocks - see Admin\PageController for the allowed shapes.
 */
class Page extends Model
{
    /** slug => title for the pages that must always exist. */
    public const SYSTEM_PAGES = [
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms & Conditions',
        'booking-terms' => 'Reservation Terms',
        'faq' => 'Frequently Asked Questions',
    ];

    protected $fillable = [
        'slug',
        'title',
        'subtitle',
        'blocks',
        'seo_title',
        'seo_description',
        'status',
        'published_at',
        'is_system',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'published_at' => 'datetime',
            'is_system' => 'boolean',
        ];
    }
}
