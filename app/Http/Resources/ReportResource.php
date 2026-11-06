<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_code' => $this->referenceCode(),
            'target_type' => $this->target_type,
            'reference_id' => $this->reference_id,
            'reference_label' => $this->referenceLabel(),
            'reason' => $this->reason,
            'severity' => $this->severity,
            'details' => $this->details,
            'requested_action' => $this->requested_action,
            'status' => $this->status,
            'attachments' => $this->attachments,
            'notes' => ReportNoteResource::collection($this->whenLoaded('notes')),
            'created_at' => $this->created_at,
            'resolved_at' => $this->resolved_at,
        ];
    }
}