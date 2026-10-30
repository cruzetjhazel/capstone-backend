<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking' => [
                'id' => $this->booking->id,
                'status' => $this->booking->status->value,
                'payment_status' => $this->booking->payment_status->value,
                'payment_plan' => $this->booking->payment_plan?->value,
                'total_amount' => $this->booking->total_price,
                'amount_paid' => $this->booking->totalPaid(),
                'remaining_balance' => $this->booking->remainingBalance(),
            ],
            'client' => [
                'id' => $this->booking->client?->id,
                'name' => $this->booking->client?->name ?? $this->booking->walkInClient?->name ?? 'Walk-in client',
            ],
            'event_type' => $this->booking->event_type,
            'total_amount' => $this->booking->total_price,
            'amount_paid' => $this->booking->totalPaid(),
            'remaining_balance' => $this->booking->remainingBalance(),
            'payment_status' => $this->booking->payment_status->value,
            'type' => $this->type->value,
            'method' => $this->method,
            'plan' => $this->plan?->value,
            'amount' => $this->amount,
            'reference_number' => $this->reference_number,
            'payer_name' => $this->payer_name,
            'payment_date' => $this->payment_date->format('Y-m-d'),
            'notes' => $this->notes,
            'matching_status' => $this->matching_status->value,
            'verified_by' => $this->verified_by,
            'verified_by_name' => $this->verifiedBy?->name,
            'verified_at' => $this->verified_at,
            'verification_action' => $this->verification_action,
            'verification_notes' => $this->verification_notes,
            'created_at' => $this->created_at,
        ];
    }
}