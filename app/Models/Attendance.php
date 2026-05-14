<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'timestamp',
        'status1',
        'status2',
        'status3',
        'status4',
        'status5',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'status1' => 'integer',
        'status2' => 'integer',
        'status3' => 'integer',
        'status4' => 'integer',
        'status5' => 'integer',
    ];
}
