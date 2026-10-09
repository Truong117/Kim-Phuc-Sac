<?php

namespace App\Http\Resources;

use App\Models\ReportComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportComment */
class ReportCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ],
            'content' => $this->content,
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
