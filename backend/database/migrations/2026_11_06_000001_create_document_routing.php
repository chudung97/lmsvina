<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Quy trình đóng dấu kiểu "chuyển tay": tài liệu luôn có một người đang giữ; người
 * giữ đặt/sửa/xóa con dấu của mình (nhiều dấu, nhiều trang), rồi chọn người chuyển
 * tiếp hoặc hoàn tất. Dấu chỉ sửa được khi người đặt dấu đang giữ tài liệu.
 *
 * - document_workflows: người đang giữ và trạng thái (in_progress | completed).
 * - document_seals: từng con dấu đã đặt (ảnh dấu được chụp lại khi đặt).
 * - document_routes: lịch sử tải lên / chuyển tiếp / hoàn tất.
 *
 * Tài liệu cũ (chuỗi ký cố định) được chuyển sang mô hình mới; bảng cũ giữ nguyên.
 *
 * php artisan migrate --path=database/migrations/2026_11_06_000001_create_document_routing.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_workflows')) {
            Schema::create('document_workflows', function (Blueprint $table): void {
                $table->unsignedInteger('document_id')->primary();
                $table->integer('holder_user_id');
                $table->string('status', 20)->default('in_progress');
                $table->timestamp('updated_at')->nullable();
                $table->index(['holder_user_id', 'status'], 'document_workflows_holder_status_index');
            });
        }

        if (! Schema::hasTable('document_seals')) {
            Schema::create('document_seals', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('document_id');
                $table->integer('user_id');
                $table->unsignedInteger('page')->default(1);
                $table->double('x');
                $table->double('y');
                $table->double('width');
                $table->double('height');
                $table->double('rotation')->default(0);
                $table->string('image_path', 500)->nullable();
                $table->decimal('aspect', 8, 4)->nullable();
                $table->string('user_name', 120)->default('');
                $table->string('seal_text', 3)->default('');
                $table->string('signature_name', 120)->default('');
                $table->char('color', 7)->default('#1d4ed8');
                $table->timestamps();
                $table->index('document_id', 'document_seals_document_index');
            });
        }

        if (! Schema::hasTable('document_routes')) {
            Schema::create('document_routes', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('document_id');
                $table->integer('from_user_id')->nullable();
                $table->integer('to_user_id')->nullable();
                $table->string('action', 20);
                $table->string('note', 500)->default('');
                $table->dateTime('created_at')->useCurrent();
                $table->index('document_id', 'document_routes_document_index');
                $table->index('to_user_id', 'document_routes_to_index');
                $table->index('from_user_id', 'document_routes_from_index');
            });
        }

        $this->convertExistingDocuments();
    }

    private function convertExistingDocuments(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }
        $images = Schema::hasTable('document_seal_images')
            ? DB::table('document_seal_images')->get()->keyBy(fn ($row) => $row->document_id.'|'.$row->user_id)
            : collect();

        foreach (DB::table('documents')->orderBy('id')->get() as $document) {
            if (DB::table('document_workflows')->where('document_id', $document->id)->exists()) {
                continue;
            }
            $completed = $document->completed_at !== null;
            $positions = DB::table('seal_positions')
                ->join('users', 'users.id', '=', 'seal_positions.user_id')
                ->leftJoin('document_signers', function ($join): void {
                    $join->on('document_signers.document_id', '=', 'seal_positions.document_id')
                        ->on('document_signers.user_id', '=', 'seal_positions.user_id');
                })
                ->where('seal_positions.document_id', $document->id)
                ->orderBy('document_signers.sequence')
                ->get(['seal_positions.*', 'users.name', 'users.seal_text', 'users.signature_name', 'users.color']);
            $completedSeals = DB::table('completed_seals')->where('document_id', $document->id)->get()->keyBy('user_id');
            $holder = $completed
                ? $document->owner_id
                : ($positions->first(fn ($position) => $position->signed_at === null)?->user_id ?? $document->owner_id);

            DB::table('document_workflows')->insert([
                'document_id' => $document->id,
                'holder_user_id' => (int) $holder,
                'status' => $completed ? 'completed' : 'in_progress',
                'updated_at' => $document->completed_at ?? $document->uploaded_at,
            ]);
            DB::table('document_routes')->insert([
                'document_id' => $document->id,
                'from_user_id' => null,
                'to_user_id' => $document->owner_id,
                'action' => 'upload',
                'note' => '',
                'created_at' => $document->uploaded_at,
            ]);
            foreach ($positions as $position) {
                if ($position->signed_at === null) {
                    continue;
                }
                $snapshot = $completedSeals[$position->user_id] ?? null;
                $image = $images[$document->id.'|'.$position->user_id] ?? null;
                DB::table('document_seals')->insert([
                    'document_id' => $document->id,
                    'user_id' => $position->user_id,
                    'page' => $snapshot->page ?? $position->page,
                    'x' => $snapshot->x ?? $position->x,
                    'y' => $snapshot->y ?? $position->y,
                    'width' => $snapshot->width ?? $position->width,
                    'height' => $snapshot->height ?? $position->height,
                    'rotation' => $snapshot->rotation ?? $position->rotation,
                    'image_path' => $image->image_path ?? null,
                    'aspect' => $image->aspect ?? null,
                    'user_name' => mb_substr((string) ($snapshot->user_name ?? $position->name), 0, 120),
                    'seal_text' => mb_substr((string) ($snapshot->seal_text ?? $position->seal_text), 0, 3),
                    'signature_name' => mb_substr((string) ($snapshot->signature_name ?? $position->signature_name), 0, 120),
                    'color' => $snapshot->color ?? $position->color,
                    'created_at' => $position->signed_at,
                    'updated_at' => $position->signed_at,
                ]);
                DB::table('document_routes')->insert([
                    'document_id' => $document->id,
                    'from_user_id' => $position->user_id,
                    'to_user_id' => null,
                    'action' => 'stamp',
                    'note' => 'Chuyển đổi từ chuỗi ký cũ',
                    'created_at' => $position->signed_at,
                ]);
            }
            if ($completed) {
                DB::table('document_routes')->insert([
                    'document_id' => $document->id,
                    'from_user_id' => $holder,
                    'to_user_id' => null,
                    'action' => 'complete',
                    'note' => '',
                    'created_at' => $document->completed_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routes');
        Schema::dropIfExists('document_seals');
        Schema::dropIfExists('document_workflows');
    }
};
