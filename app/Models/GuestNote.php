<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestNote extends Model
{
    protected $fillable = ['email', 'note', 'updated_by_admin_id'];
}
