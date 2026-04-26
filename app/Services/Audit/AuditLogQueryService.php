<?php

namespace App\Services\Audit;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class AuditLogQueryService
{
    /**
     * @param  array{
     *     keyword?: string,
     *     log_name?: string,
     *     event?: string,
     *     causer_id?: int,
     *     subject_type?: string,
     *     subject_id?: int,
     *     batch_uuid?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     per_page?: int
     * }  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $this->filteredQuery($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Activity $activity): array => $this->transformRecord($activity));
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    public function find(Activity $activity): array
    {
        return [
            'data' => $this->transformRecord($activity->loadMissing(['causer', 'subject'])),
        ];
    }

    /**
     * @param  array{
     *     keyword?: string,
     *     log_name?: string,
     *     event?: string,
     *     causer_id?: int,
     *     subject_type?: string,
     *     subject_id?: int,
     *     batch_uuid?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     per_page?: int
     * }  $filters
     */
    public function filteredQuery(array $filters = []): Builder
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->when(
                isset($filters['keyword']),
                fn (Builder $query): Builder => $this->applyKeywordFilter($query, (string) $filters['keyword'])
            )
            ->when(
                isset($filters['log_name']),
                fn (Builder $query): Builder => $query->where('log_name', $filters['log_name'])
            )
            ->when(
                isset($filters['event']),
                fn (Builder $query): Builder => $query->where('event', $filters['event'])
            )
            ->when(
                isset($filters['causer_id']),
                fn (Builder $query): Builder => $query->where('causer_type', User::class)->where('causer_id', $filters['causer_id'])
            )
            ->when(
                isset($filters['subject_type']),
                fn (Builder $query): Builder => $query->where('subject_type', $filters['subject_type'])
            )
            ->when(
                isset($filters['subject_id']),
                fn (Builder $query): Builder => $query->where('subject_id', $filters['subject_id'])
            )
            ->when(
                isset($filters['batch_uuid']),
                fn (Builder $query): Builder => $query->where('batch_uuid', $filters['batch_uuid'])
            )
            ->when(
                isset($filters['from_date']),
                fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $filters['from_date'])
            )
            ->when(
                isset($filters['to_date']),
                fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $filters['to_date'])
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function transformRecord(Activity $activity): array
    {
        $properties = $activity->properties instanceof Collection
            ? $activity->properties
            : collect();

        return [
            'id' => $activity->id,
            'logName' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'batchUuid' => $activity->batch_uuid,
            'createdAt' => $activity->created_at?->toIso8601String(),
            'causer' => $this->transformCauser($activity->causer_type, $activity->causer),
            'subject' => $this->transformSubject($activity->subject_type, $activity->subject),
            'changes' => [
                'attributes' => $properties->get('attributes', []),
                'old' => $properties->get('old', []),
            ],
            'metadata' => $properties->except(['attributes', 'old'])->all(),
        ];
    }

    private function applyKeywordFilter(Builder $query, string $keyword): Builder
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return $query;
        }

        $isNumericKeyword = ctype_digit($keyword);

        return $query->where(function (Builder $keywordQuery) use ($keyword, $isNumericKeyword): void {
            $keywordQuery
                ->where('log_name', 'like', '%'.$keyword.'%')
                ->orWhere('event', 'like', '%'.$keyword.'%')
                ->orWhere('description', 'like', '%'.$keyword.'%')
                ->orWhere('batch_uuid', 'like', '%'.$keyword.'%')
                ->orWhere('subject_type', 'like', '%'.$keyword.'%');

            if ($isNumericKeyword) {
                $keywordQuery->orWhere('id', (int) $keyword)
                    ->orWhere('causer_id', (int) $keyword)
                    ->orWhere('subject_id', (int) $keyword);
            }

            $keywordQuery->orWhereHasMorph(
                'causer',
                [User::class, Employee::class],
                function (Builder $causerQuery, string $type) use ($keyword): void {
                    if ($type === User::class) {
                        $causerQuery->where(function (Builder $userQuery) use ($keyword): void {
                            $userQuery->where('name', 'like', '%'.$keyword.'%')
                                ->orWhere('email', 'like', '%'.$keyword.'%');
                        });

                        return;
                    }

                    $causerQuery->where(function (Builder $employeeQuery) use ($keyword): void {
                        $employeeQuery->where('first_name', 'like', '%'.$keyword.'%')
                            ->orWhere('last_name', 'like', '%'.$keyword.'%')
                            ->orWhere('email', 'like', '%'.$keyword.'%');
                    });
                }
            )
                ->orWhereHasMorph(
                    'subject',
                    [User::class, Employee::class, Attendance::class, AttendanceCorrectionRequest::class, LeaveRequest::class, Role::class, Permission::class],
                    function (Builder $subjectQuery, string $type) use ($keyword, $isNumericKeyword): void {
                        if ($type === User::class) {
                            $subjectQuery->where(function (Builder $userQuery) use ($keyword): void {
                                $userQuery->where('name', 'like', '%'.$keyword.'%')
                                    ->orWhere('email', 'like', '%'.$keyword.'%');
                            });

                            return;
                        }

                        if ($type === Employee::class) {
                            $subjectQuery->where(function (Builder $employeeQuery) use ($keyword): void {
                                $employeeQuery->where('first_name', 'like', '%'.$keyword.'%')
                                    ->orWhere('last_name', 'like', '%'.$keyword.'%')
                                    ->orWhere('email', 'like', '%'.$keyword.'%');
                            });

                            return;
                        }

                        if ($type === Attendance::class) {
                            $subjectQuery->where('attendance_date', 'like', '%'.$keyword.'%');

                            if ($isNumericKeyword) {
                                $subjectQuery->orWhere('id', (int) $keyword);
                            }

                            return;
                        }

                        if ($type === AttendanceCorrectionRequest::class) {
                            if ($isNumericKeyword) {
                                $subjectQuery->where('id', (int) $keyword);
                            }

                            return;
                        }

                        if ($type === LeaveRequest::class) {
                            $subjectQuery->where(function (Builder $leaveQuery) use ($keyword, $isNumericKeyword): void {
                                $leaveQuery->where('type', 'like', '%'.$keyword.'%')
                                    ->orWhere('status', 'like', '%'.$keyword.'%')
                                    ->orWhere('start_date', 'like', '%'.$keyword.'%')
                                    ->orWhere('end_date', 'like', '%'.$keyword.'%');

                                if ($isNumericKeyword) {
                                    $leaveQuery->orWhere('id', (int) $keyword)
                                        ->orWhere('employee_id', (int) $keyword);
                                }
                            });

                            return;
                        }

                        $subjectQuery->where('name', 'like', '%'.$keyword.'%');
                    }
                );
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function transformCauser(?string $type, ?Model $causer): ?array
    {
        if ($causer === null) {
            return null;
        }

        $payload = [
            'type' => $type,
            'id' => $causer->getKey(),
        ];

        if ($causer instanceof User) {
            $payload['name'] = $causer->displayName();
            $payload['email'] = $causer->email;
        }

        if ($causer instanceof Employee) {
            $payload['name'] = $causer->full_name;
            $payload['email'] = $causer->email;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function transformSubject(?string $type, ?Model $subject): ?array
    {
        if ($subject === null) {
            return $type !== null
                ? [
                    'type' => $type,
                    'id' => null,
                    'label' => null,
                ]
                : null;
        }

        return [
            'type' => $type,
            'id' => $subject->getKey(),
            'label' => $this->subjectLabel($subject),
        ];
    }

    private function subjectLabel(Model $subject): ?string
    {
        if ($subject instanceof User) {
            return $subject->displayName();
        }

        if ($subject instanceof Employee) {
            return $subject->full_name;
        }

        if ($subject instanceof Attendance) {
            return sprintf(
                'Attendance #%d (%s)',
                $subject->id,
                $subject->attendance_date?->toDateString() ?? 'n/a',
            );
        }

        if ($subject instanceof AttendanceCorrectionRequest) {
            return sprintf('Correction Request #%d', $subject->id);
        }

        if ($subject instanceof LeaveRequest) {
            return sprintf(
                'Leave Request #%d (%s to %s)',
                $subject->id,
                $subject->start_date?->toDateString() ?? 'n/a',
                $subject->end_date?->toDateString() ?? 'n/a',
            );
        }

        if ($subject instanceof Role || $subject instanceof Permission) {
            return $subject->name;
        }

        return method_exists($subject, 'getAttribute')
            ? $subject->getAttribute('name')
            : class_basename($subject);
    }
}
