<?php

namespace App\Http\Controllers;

use App\Support\SealImages;
use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Đóng dấu PDF theo kiểu "chuyển tay".
 *
 * - Tài liệu luôn có một người đang giữ (document_workflows.holder_user_id).
 * - Người đang giữ được thêm/sửa/xóa con dấu CỦA MÌNH (nhiều dấu, nhiều trang),
 *   chọn người để chuyển tiếp, hoặc hoàn tất & khóa tài liệu.
 * - Sau khi chuyển đi, dấu của người đó bị khóa; nếu tài liệu được chuyển lại thì
 *   người đó sửa tiếp được. Tài liệu hoàn tất thì mọi dấu đều khóa.
 * - Người được xem: người tạo và mọi người từng nhận/chuyển tài liệu.
 */
class DocumentsController extends ApiController
{
    private const MAX_SEALS_PER_DOCUMENT = 200;

    /** Tài liệu đang xử lý: tôi đang giữ, và tài liệu tôi đã tham gia nhưng người khác đang giữ. */
    public function mine(Request $request): JsonResponse
    {
        $userId = (int) $this->user($request)->id;
        $documents = $this->documentListQuery($userId)
            ->where('document_workflows.status', 'in_progress')
            ->orderByDesc('document_workflows.updated_at')
            ->orderByDesc('documents.id')
            ->get($this->listColumns());
        $rows = $this->listRows($documents, $userId);

        return response()->json([
            'holding' => array_values(array_filter($rows, fn (array $row): bool => $row['isHolder'])),
            'sent' => array_values(array_filter($rows, fn (array $row): bool => ! $row['isHolder'])),
        ]);
    }

    /** Tài liệu đã hoàn tất mà người dùng là người tạo hoặc đã tham gia. */
    public function completed(Request $request): JsonResponse
    {
        $userId = (int) $this->user($request)->id;
        $documents = $this->documentListQuery($userId)
            ->where('document_workflows.status', 'completed')
            ->orderByDesc('documents.completed_at')
            ->orderByDesc('documents.id')
            ->get($this->listColumns());

        return response()->json($this->listRows($documents, $userId));
    }

    /** Người nhận có thể chọn khi chuyển tiếp: mọi tài khoản đang hoạt động, trừ chính mình. */
    public function recipients(Request $request): JsonResponse
    {
        $signers = DB::table('user_permissions')->where('permission', 'pdf_signer')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return response()->json(DB::table('users')
            ->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->where('users.active', 1)
            ->where('users.id', '!=', $this->user($request)->id)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.user_code', 'users.role', 'users.org_level', 'departments.name as department_name'])
            ->map(fn ($user) => [
                'id' => $this->responseUserId($user->id),
                'name' => $user->name,
                'userCode' => $user->user_code,
                'departmentName' => $user->department_name,
                'canSign' => $user->role === 'admin' || $user->org_level === 'ceo' || in_array((int) $user->id, $signers, true),
            ]));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $documentId = filter_var($id, FILTER_VALIDATE_INT);
        $user = $this->user($request);
        if (! $documentId || ! $this->canView((int) $user->id, $documentId)) {
            return $this->error('Không tìm thấy tài liệu hoặc bạn không được phép truy cập.', 404);
        }

        return response()->json($this->detail($documentId, $user));
    }

    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return $this->error('Vui lòng chọn tệp PDF.', 400);
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return $this->error('Chỉ chấp nhận tài liệu PDF.', 400);
        }
        if ($file->getSize() > 25 * 1024 * 1024) {
            return $this->error('Tệp PDF vượt quá giới hạn 25 MB.', 400);
        }

        $user = $this->user($request);
        $dataDirectory = env('PDF_SEAL_DATA_DIR', base_path('../data'));
        $uploadsDirectory = $dataDirectory.DIRECTORY_SEPARATOR.'uploads';
        if (! is_dir($uploadsDirectory) && ! mkdir($uploadsDirectory, 0770, true) && ! is_dir($uploadsDirectory)) {
            throw new \RuntimeException('Could not create the PDF uploads directory.');
        }
        $filename = (string) Str::uuid().'.pdf';
        $file->move($uploadsDirectory, $filename);
        $filePath = $uploadsDirectory.DIRECTORY_SEPARATOR.$filename;
        if (file_get_contents($filePath, false, null, 0, 5) !== '%PDF-') {
            unlink($filePath);

            return $this->error('Tệp được chọn không phải PDF hợp lệ.', 400);
        }
        $name = basename($file->getClientOriginalName());
        $committed = false;
        try {
            $documentId = DB::transaction(function () use ($user, $name, $filePath): int {
                $id = (int) DB::table('documents')->insertGetId([
                    'name' => $name,
                    'file_path' => $filePath,
                    'owner_id' => $user->id,
                ]);
                DB::table('document_workflows')->insert([
                    'document_id' => $id,
                    'holder_user_id' => $user->id,
                    'status' => 'in_progress',
                    'updated_at' => now(),
                ]);
                $this->route($id, null, (int) $user->id, 'upload');

                return $id;
            });
            $committed = true;
        } finally {
            if (! $committed && is_file($filePath)) {
                unlink($filePath);
            }
        }

        return response()->json($this->detail($documentId, $user), 201);
    }

    public function addSeal(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $position = $this->position($request);
        if (is_string($position)) {
            return $this->error($position, 400);
        }
        if (! $this->hasPermission($user, 'pdf_signer')) {
            return $this->error('Bạn chưa được cấp quyền đóng dấu tài liệu PDF.', 403);
        }

        return $this->mutate($request, $id, function (int $documentId) use ($user, $position): ?JsonResponse {
            if (DB::table('document_seals')->where('document_id', $documentId)->count() >= self::MAX_SEALS_PER_DOCUMENT) {
                return $this->error('Tài liệu đã đạt số con dấu tối đa.', 409);
            }
            $image = SealImages::copyProfileImage($user->id);
            $profileUser = DB::table('users')->where('id', $user->id)->first(['name', 'seal_text', 'signature_name', 'color']);
            try {
                DB::table('document_seals')->insert([
                    'document_id' => $documentId,
                    'user_id' => $user->id,
                    ...$position,
                    'height' => $image ? round($position['width'] / max(0.2, $image['aspect']), 2) : $position['height'],
                    'image_path' => $image['path'] ?? null,
                    'aspect' => $image['aspect'] ?? null,
                    'user_name' => mb_substr((string) $profileUser->name, 0, 120),
                    'seal_text' => mb_substr((string) $profileUser->seal_text, 0, 3),
                    'signature_name' => mb_substr((string) $profileUser->signature_name, 0, 120),
                    'color' => preg_match('/^#[\da-f]{6}$/i', (string) $profileUser->color) ? $profileUser->color : '#1d4ed8',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                if ($image) {
                    @unlink($image['path']);
                }
                throw $exception;
            }

            return null;
        }, 201);
    }

    public function updateSeal(Request $request, string $id, string $sealId): JsonResponse
    {
        $position = $this->position($request);
        if (is_string($position)) {
            return $this->error($position, 400);
        }

        return $this->mutate($request, $id, function (int $documentId) use ($request, $sealId, $position): ?JsonResponse {
            $seal = $this->ownSeal($request, $documentId, $sealId);
            if ($seal instanceof JsonResponse) {
                return $seal;
            }
            DB::table('document_seals')->where('id', $seal->id)->update([
                ...$position,
                'height' => $seal->aspect ? round($position['width'] / max(0.2, (float) $seal->aspect), 2) : $position['height'],
                'updated_at' => now(),
            ]);

            return null;
        });
    }

    public function deleteSeal(Request $request, string $id, string $sealId): JsonResponse
    {
        $imagePath = null;
        $response = $this->mutate($request, $id, function (int $documentId) use ($request, $sealId, &$imagePath): ?JsonResponse {
            $seal = $this->ownSeal($request, $documentId, $sealId);
            if ($seal instanceof JsonResponse) {
                return $seal;
            }
            DB::table('document_seals')->where('id', $seal->id)->delete();
            $imagePath = $seal->image_path;

            return null;
        });
        if ($response->getStatusCode() === 200 && $imagePath && is_file($imagePath)) {
            @unlink($imagePath);
        }

        return $response;
    }

    public function forward(Request $request, string $id): JsonResponse
    {
        $toUserId = filter_var($request->input('toUserId'), FILTER_VALIDATE_INT);
        $note = is_string($request->input('note')) ? trim($request->input('note')) : '';
        if (! $toUserId || mb_strlen($note) > 500) {
            return $this->error('Chọn người nhận hợp lệ; ghi chú tối đa 500 ký tự.', 400);
        }
        $user = $this->user($request);
        if ($toUserId === (int) $user->id) {
            return $this->error('Không thể chuyển tiếp cho chính mình.', 400);
        }
        if (! $this->activeUsersExist([$toUserId])) {
            return $this->error('Người nhận không tồn tại hoặc không còn hoạt động.', 400);
        }

        return $this->mutate($request, $id, function (int $documentId) use ($user, $toUserId, $note): ?JsonResponse {
            DB::table('document_workflows')->where('document_id', $documentId)->update([
                'holder_user_id' => $toUserId,
                'updated_at' => now(),
            ]);
            $this->route($documentId, (int) $user->id, $toUserId, 'forward', $note);

            return null;
        }, 200, [$toUserId]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        return $this->mutate($request, $id, function (int $documentId) use ($user): ?JsonResponse {
            if (! DB::table('document_seals')->where('document_id', $documentId)->exists()) {
                return $this->error('Tài liệu chưa có con dấu nào; hãy đóng dấu trước khi hoàn tất.', 409);
            }
            DB::table('document_workflows')->where('document_id', $documentId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);
            DB::table('documents')->where('id', $documentId)->update(['completed_at' => now()]);
            $this->route($documentId, (int) $user->id, null, 'complete');

            return null;
        });
    }

    public function file(Request $request, string $id)
    {
        $document = $this->viewableDocument($request, $id);
        if (! $document) {
            return $this->error('Không tìm thấy tài liệu.', 404);
        }
        if (! is_file($document->file_path)) {
            throw new \RuntimeException('The stored PDF file is missing.');
        }

        return response()->download($document->file_path, $document->name, ['Content-Type' => 'application/pdf']);
    }

    /** PDF có nhúng các con dấu (hình ảnh, không phải chữ ký số). */
    public function stamped(Request $request, string $id)
    {
        $document = $this->viewableDocument($request, $id);
        if (! $document) {
            return $this->error('Không tìm thấy tài liệu.', 404);
        }
        if (! is_file($document->file_path)) {
            throw new \RuntimeException('The stored PDF file is missing.');
        }
        $marks = DB::table('document_seals')
            ->where('document_id', $document->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($seal) => [
                'page' => (int) $seal->page,
                'x' => (float) $seal->x,
                'y' => (float) $seal->y,
                'width' => (float) $seal->width,
                'height' => (float) $seal->height,
                'rotation' => (float) $seal->rotation,
                'sealText' => $seal->seal_text,
                'signatureName' => $seal->signature_name,
                'color' => $seal->color,
                'imagePath' => $seal->image_path && is_file($seal->image_path) ? $seal->image_path : null,
            ])->all();
        $outputPath = storage_path('app/'.Str::uuid().'.pdf');
        $process = new Process(['node', base_path('../scripts/stamp-pdf.js')], base_path('..'));
        $process->setInput(json_encode([
            'sourcePath' => $document->file_path,
            'outputPath' => $outputPath,
            'marks' => $marks,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();
        if (! is_file($outputPath)) {
            throw new \RuntimeException('PDF stamping completed without producing a file.');
        }

        return response()
            ->download($outputPath, 'stamped-'.$document->name, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    public function sealImage(Request $request, string $id, string $sealId)
    {
        $document = $this->viewableDocument($request, $id);
        $seal = $document
            ? DB::table('document_seals')->where('document_id', $document->id)->where('id', (int) $sealId)->first(['image_path'])
            : null;
        if (! $seal?->image_path || ! is_file($seal->image_path)) {
            return $this->error('Không tìm thấy ảnh con dấu.', 404);
        }

        return response()->file($seal->image_path, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=31536000']);
    }

    /** Cập nhật kiểu dấu chữ cũ (giữ tương thích). */
    public function setSealProfile(Request $request): JsonResponse
    {
        $sealText = $request->input('sealText');
        $signatureName = $request->input('signatureName');
        $color = $request->input('color');
        if (! is_string($sealText) || mb_strlen($sealText) > 3
            || ! is_string($signatureName) || mb_strlen($signatureName) > 120
            || ! is_string($color) || ! preg_match('/^#[\da-f]{6}$/i', $color)) {
            return $this->error('Thông tin con dấu không hợp lệ.', 400);
        }
        $userId = $this->user($request)->id;
        DB::table('users')->where('id', $userId)->update([
            'seal_text' => $sealText,
            'signature_name' => $signatureName,
            'color' => $color,
        ]);

        return response()->json(['account' => $this->publicUser(DB::table('users')->where('id', $userId)->first())]);
    }

    /**
     * Chạy một thay đổi trên tài liệu mà người dùng đang giữ, trong giao dịch có khóa.
     * Trả về chi tiết tài liệu sau khi thay đổi.
     */
    private function mutate(Request $request, string $id, callable $change, int $status = 200, array $extraRecipients = []): JsonResponse
    {
        $documentId = filter_var($id, FILTER_VALIDATE_INT);
        $user = $this->user($request);
        if (! $documentId || ! $this->canView((int) $user->id, $documentId)) {
            return $this->error('Không tìm thấy tài liệu hoặc bạn không được phép truy cập.', 404);
        }
        $error = DB::transaction(function () use ($documentId, $user, $change): ?JsonResponse {
            $workflow = DB::table('document_workflows')->where('document_id', $documentId)->lockForUpdate()->first();
            if (! $workflow) {
                return $this->error('Không tìm thấy quy trình của tài liệu.', 404);
            }
            if ($workflow->status !== 'in_progress') {
                return $this->error('Tài liệu đã hoàn tất và bị khóa.', 409);
            }
            if ((int) $workflow->holder_user_id !== (int) $user->id) {
                return $this->error('Tài liệu đang ở chỗ người khác; bạn chỉ sửa được khi tài liệu được chuyển lại cho bạn.', 403);
            }

            return $change($documentId);
        });
        if ($error) {
            return $error;
        }
        $this->broadcast($documentId, $extraRecipients);

        return response()->json($this->detail($documentId, $user), $status);
    }

    private function ownSeal(Request $request, int $documentId, string $sealId): object
    {
        $seal = DB::table('document_seals')->where('document_id', $documentId)->where('id', (int) $sealId)->first();
        if (! $seal) {
            return $this->error('Không tìm thấy con dấu.', 404);
        }
        if ((int) $seal->user_id !== (int) $this->user($request)->id) {
            return $this->error('Bạn chỉ được sửa hoặc xóa con dấu của chính mình.', 403);
        }

        return $seal;
    }

    private function position(Request $request): array|string
    {
        $page = $request->input('page');
        $values = [
            'x' => $request->input('x'),
            'y' => $request->input('y'),
            'width' => $request->input('width'),
            'height' => $request->input('height'),
        ];
        if (! is_int($page) || $page < 1 || $page > 10000) {
            return 'Trang đóng dấu không hợp lệ.';
        }
        foreach ($values as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 20000) {
                return 'Vị trí con dấu không hợp lệ.';
            }
        }
        if ((float) $values['width'] < 10 || (float) $values['height'] < 10) {
            return 'Con dấu quá nhỏ.';
        }

        return ['page' => $page, 'rotation' => 0, ...array_map(fn ($value): float => round((float) $value, 2), $values)];
    }

    private function route(int $documentId, ?int $from, ?int $to, string $action, string $note = ''): void
    {
        DB::table('document_routes')->insert([
            'document_id' => $documentId,
            'from_user_id' => $from,
            'to_user_id' => $to,
            'action' => $action,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function participantIds(int $documentId): array
    {
        $owner = DB::table('documents')->where('id', $documentId)->value('owner_id');
        $routes = DB::table('document_routes')->where('document_id', $documentId)->get(['from_user_id', 'to_user_id']);
        $holder = DB::table('document_workflows')->where('document_id', $documentId)->value('holder_user_id');

        return array_values(array_unique(array_filter(array_map('intval', [
            $owner,
            $holder,
            ...$routes->pluck('from_user_id')->all(),
            ...$routes->pluck('to_user_id')->all(),
        ]))));
    }

    private function broadcast(int $documentId, array $extra = []): void
    {
        WorkdeskBroadcast::users([...$this->participantIds($documentId), ...$extra], 'pdf');
    }

    private function canView(int $userId, int $documentId): bool
    {
        return DB::table('documents')->where('id', $documentId)->where(fn ($query) => $this->participatedBy($query, $userId))->exists();
    }

    private function viewableDocument(Request $request, string $id): ?object
    {
        $documentId = filter_var($id, FILTER_VALIDATE_INT);
        if (! $documentId || ! $this->canView((int) $this->user($request)->id, $documentId)) {
            return null;
        }

        return DB::table('documents')->where('id', $documentId)->first(['id', 'name', 'file_path']);
    }

    private function participatedBy($query, int $userId): void
    {
        $query->where('documents.owner_id', $userId)
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('document_workflows')
                ->whereColumn('document_workflows.document_id', 'documents.id')
                ->where('document_workflows.holder_user_id', $userId))
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('document_routes')
                ->whereColumn('document_routes.document_id', 'documents.id')
                ->where(fn ($inner) => $inner->where('document_routes.to_user_id', $userId)
                    ->orWhere('document_routes.from_user_id', $userId)));
    }

    private function documentListQuery(int $userId)
    {
        return DB::table('documents')
            ->join('document_workflows', 'document_workflows.document_id', '=', 'documents.id')
            ->leftJoin('users as owners', 'owners.id', '=', 'documents.owner_id')
            ->leftJoin('users as holders', 'holders.id', '=', 'document_workflows.holder_user_id')
            ->where(fn ($query) => $this->participatedBy($query, $userId));
    }

    private function listColumns(): array
    {
        return [
            'documents.id',
            'documents.name',
            'documents.owner_id as ownerId',
            'documents.uploaded_at as uploadedAt',
            'documents.completed_at as completedAt',
            'document_workflows.holder_user_id as holderId',
            'document_workflows.status',
            'document_workflows.updated_at as updatedAt',
            'owners.name as ownerName',
            'holders.name as holderName',
        ];
    }

    private function listRows($documents, int $userId): array
    {
        if ($documents->isEmpty()) {
            return [];
        }
        $ids = $documents->pluck('id');
        $sealCounts = DB::table('document_seals')->whereIn('document_id', $ids)
            ->groupBy('document_id')->selectRaw('document_id, count(*) as total')->pluck('total', 'document_id');
        $mySeals = DB::table('document_seals')->whereIn('document_id', $ids)->where('user_id', $userId)
            ->groupBy('document_id')->selectRaw('document_id, count(*) as total')->pluck('total', 'document_id');
        $lastForward = DB::table('document_routes')
            ->leftJoin('users as senders', 'senders.id', '=', 'document_routes.from_user_id')
            ->whereIn('document_routes.document_id', $ids)
            ->where('document_routes.action', 'forward')
            ->orderBy('document_routes.id')
            ->get(['document_routes.document_id', 'senders.name as fromName', 'document_routes.note'])
            ->keyBy('document_id');

        return $documents->map(fn ($document) => [
            'id' => $document->id,
            'name' => $document->name,
            'ownerId' => $this->responseUserId($document->ownerId),
            'ownerName' => $document->ownerName,
            'holderId' => $this->responseUserId($document->holderId),
            'holderName' => $document->holderName,
            'isHolder' => (int) $document->holderId === $userId,
            'role' => (int) $document->ownerId === $userId ? 'owner' : 'participant',
            'status' => $document->status,
            'uploadedAt' => $document->uploadedAt,
            'updatedAt' => $document->updatedAt,
            'completedAt' => $document->completedAt,
            'sealCount' => (int) ($sealCounts[$document->id] ?? 0),
            'mySealCount' => (int) ($mySeals[$document->id] ?? 0),
            'lastFromName' => $lastForward[$document->id]->fromName ?? null,
            'lastNote' => $lastForward[$document->id]->note ?? '',
            'stampedFileUrl' => "/api/documents/{$document->id}/stamped",
        ])->all();
    }

    private function detail(int $documentId, object $sessionUser): array
    {
        $userId = (int) $sessionUser->id;
        $document = DB::table('documents')
            ->join('document_workflows', 'document_workflows.document_id', '=', 'documents.id')
            ->leftJoin('users as owners', 'owners.id', '=', 'documents.owner_id')
            ->leftJoin('users as holders', 'holders.id', '=', 'document_workflows.holder_user_id')
            ->where('documents.id', $documentId)
            ->first([...$this->listColumns()]);
        $isHolder = (int) $document->holderId === $userId;
        $inProgress = $document->status === 'in_progress';
        $canStamp = $this->hasPermission($sessionUser, 'pdf_signer');
        $seals = DB::table('document_seals')->where('document_id', $documentId)->orderBy('id')->get();
        $routes = DB::table('document_routes')
            ->leftJoin('users as senders', 'senders.id', '=', 'document_routes.from_user_id')
            ->leftJoin('users as receivers', 'receivers.id', '=', 'document_routes.to_user_id')
            ->where('document_routes.document_id', $documentId)
            ->orderBy('document_routes.id')
            ->get([
                'document_routes.id',
                'document_routes.action',
                'document_routes.note',
                'document_routes.created_at as createdAt',
                'document_routes.from_user_id as fromId',
                'senders.name as fromName',
                'document_routes.to_user_id as toId',
                'receivers.name as toName',
            ]);
        $profile = SealImages::profile($userId);

        return [
            'id' => $document->id,
            'name' => $document->name,
            'ownerId' => $this->responseUserId($document->ownerId),
            'ownerName' => $document->ownerName,
            'holderId' => $this->responseUserId($document->holderId),
            'holderName' => $document->holderName,
            'status' => $document->status,
            'uploadedAt' => $document->uploadedAt,
            'completedAt' => $document->completedAt,
            'role' => (int) $document->ownerId === $userId ? 'owner' : 'participant',
            'isHolder' => $isHolder,
            'canEdit' => $isHolder && $inProgress && $canStamp,
            'canForward' => $isHolder && $inProgress,
            'canComplete' => $isHolder && $inProgress && $seals->isNotEmpty(),
            'canStamp' => $canStamp,
            'fileUrl' => "/api/documents/{$document->id}/file",
            'stampedFileUrl' => "/api/documents/{$document->id}/stamped",
            'sealProfile' => [
                'configured' => (bool) $profile?->image_path,
                'aspect' => (float) ($profile->aspect ?? 1.5),
                'imageUrl' => $profile?->image_path ? '/api/seal-profile/image?v='.strtotime((string) $profile->updated_at) : null,
            ],
            'seals' => $seals->map(fn ($seal) => [
                'id' => $seal->id,
                'userId' => $this->responseUserId($seal->user_id),
                'name' => $seal->user_name,
                'page' => (int) $seal->page,
                'x' => (float) $seal->x,
                'y' => (float) $seal->y,
                'width' => (float) $seal->width,
                'height' => (float) $seal->height,
                'rotation' => (float) $seal->rotation,
                'aspect' => $seal->aspect !== null ? (float) $seal->aspect : null,
                'sealText' => $seal->seal_text,
                'signatureName' => $seal->signature_name,
                'color' => $seal->color,
                'imageUrl' => $seal->image_path ? "/api/documents/{$document->id}/seal-images/{$seal->id}" : null,
                'editable' => $isHolder && $inProgress && (int) $seal->user_id === $userId,
                'createdAt' => $seal->created_at,
                'updatedAt' => $seal->updated_at,
            ])->all(),
            'routes' => $routes->map(fn ($route) => [
                'id' => $route->id,
                'action' => $route->action,
                'note' => $route->note,
                'createdAt' => $route->createdAt,
                'fromId' => $this->responseUserId($route->fromId),
                'fromName' => $route->fromName,
                'toId' => $this->responseUserId($route->toId),
                'toName' => $route->toName,
            ])->all(),
        ];
    }
}
