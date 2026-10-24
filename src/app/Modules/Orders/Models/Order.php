<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Order placeholder for the PaymentGateway contract (Phase 1.1).
 *
 * The orders table, its columns and its relationships are introduced in
 * Phase 2 (SDD 03-data-model.md). No migration exists for this model yet.
 */
class Order extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];
}
