<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    /**
     * Log an activity.
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $properties = null,
        string $severity = 'info'
    ): ActivityLog {
        $user = Auth::user();
        $request = request();

        // Determine changed fields
        $changedFields = null;
        if ($oldValues && $newValues) {
            $changedFields = array_keys(array_diff_assoc($newValues, $oldValues));
        }

        return ActivityLog::create([
            'company_id' => $user?->company_id,
            'user_id' => $user?->id,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'action' => $action,
            'description' => $this->generateDescription($action, $subject, $model),
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'properties' => $properties,
            'severity' => $severity,
        ]);
    }

    /**
     * Log model created.
     */
    public function logCreated(Model $model, ?array $properties = null): ActivityLog
    {
        return $this->log(
            'created',
            $model,
            $model,
            null,
            $model->getAttributes(),
            $properties
        );
    }

    /**
     * Log model updated.
     */
    public function logUpdated(Model $model, array $oldValues, array $newValues, ?array $properties = null): ActivityLog
    {
        return $this->log(
            'updated',
            $model,
            $model,
            $oldValues,
            $newValues,
            $properties
        );
    }

    /**
     * Log model deleted.
     */
    public function logDeleted(Model $model, ?array $properties = null): ActivityLog
    {
        return $this->log(
            'deleted',
            $model,
            $model,
            $model->getAttributes(),
            null,
            $properties
        );
    }

    /**
     * Log model viewed.
     */
    public function logViewed(Model $model, ?array $properties = null): ActivityLog
    {
        return $this->log(
            'viewed',
            $model,
            $model,
            null,
            null,
            $properties
        );
    }

    /**
     * Generate description for activity.
     */
    private function generateDescription(string $action, ?Model $subject, ?Model $model): string
    {
        $subjectName = $subject ? class_basename($subject) : 'Unknown';
        $modelName = $model ? class_basename($model) : 'item';

        return match ($action) {
            'created' => "Created {$modelName}",
            'updated' => "Updated {$modelName}",
            'deleted' => "Deleted {$modelName}",
            'viewed' => "Viewed {$modelName}",
            default => ucfirst($action) . " {$modelName}",
        };
    }
}
