<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Contracts\Activity;

class AuditLogService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $logName,
        string $event,
        string $description,
        ?Model $causer = null,
        ?Model $subject = null,
        array $properties = [],
    ): Activity {
        $logger = activity($logName)->event($event);

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        return $logger
            ->withProperties($this->sanitize($properties))
            ->log($description);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitize(array $properties): array
    {
        $sanitized = Arr::except($properties, [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'new_password_confirmation',
            'access_token',
            'refresh_token',
            'token',
            'remember_token',
            'client_secret',
            'secret',
            'reset_token',
        ]);

        foreach ($sanitized as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            }
        }

        return $sanitized;
    }
}
