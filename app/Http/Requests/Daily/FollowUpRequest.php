<?php

namespace App\Http\Requests\Daily;

class FollowUpRequest extends DailyRequest
{
    public function rules(): array
    {
        return ['follow_up' => 'required|boolean'];
    }
}
