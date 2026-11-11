<?php

namespace Tests\Feature;

use App\Http\Controllers\DocumentsController;
use App\Http\Controllers\SealProfilesController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SealWorkflowTest extends TestCase
{
    private object $migration;

    private object $routing;

    private string $dataDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seal-test-'.uniqid();
        mkdir($this->dataDirectory, 0777, true);
        putenv("PDF_SEAL_DATA_DIR={$this->dataDirectory}");
        $_ENV['PDF_SEAL_DATA_DIR'] = $_SERVER['PDF_SEAL_DATA_DIR'] = $this->dataDirectory;

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('user_code')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('seal_text')->default('');
            $table->string('signature_name')->default('');
            $table->string('color')->default('#000000');
            $table->string('role')->default('member');
            $table->string('org_level')->default('employee');
            $table->string('department_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('departments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->string('division_id')->nullable();
        });
        Schema::create('divisions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('manager_user_id')->nullable();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });
        Schema::create('team_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('team_id');
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('permission');
        });
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('file_path');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->dateTime('uploaded_at')->useCurrent();
            $table->dateTime('completed_at')->nullable();
        });
        Schema::create('owner_workflows', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_id')->primary();
            $table->unsignedBigInteger('current_document_id')->nullable();
            $table->boolean('finalized')->default(false);
        });
        Schema::create('document_signers', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('sequence');
        });
        Schema::create('seal_positions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('page')->default(1);
            $table->double('x');
            $table->double('y');
            $table->double('width');
            $table->double('height');
            $table->double('rotation')->default(0);
            $table->dateTime('signed_at')->nullable();
            $table->primary(['user_id', 'document_id']);
        });
        Schema::create('completed_seals', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->string('seal_text');
            $table->string('signature_name');
            $table->string('color');
            $table->unsignedInteger('page');
            $table->double('x');
            $table->double('y');
            $table->double('width');
            $table->double('height');
            $table->double('rotation')->default(0);
            $table->primary(['document_id', 'user_id']);
        });
        Schema::create('workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->text('message');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
        });
        $this->migration = require base_path('database/migrations/2026_11_05_000001_create_seal_profiles.php');
        $this->migration->up();
        $this->routing = require base_path('database/migrations/2026_11_06_000001_create_document_routing.php');
        $this->routing->up();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Người tạo', 'user_code' => 'NV01', 'seal_text' => 'NT', 'signature_name' => 'Người tạo', 'color' => '#111111'],
            ['id' => 2, 'name' => 'Trưởng phòng', 'user_code' => 'NV02', 'seal_text' => 'TP', 'signature_name' => 'Trưởng phòng', 'color' => '#222222'],
            ['id' => 3, 'name' => 'Người ngoài', 'user_code' => 'NV03', 'seal_text' => 'NN', 'signature_name' => 'Người ngoài', 'color' => '#333333'],
        ]);
        DB::table('user_permissions')->insert([
            ['user_id' => 1, 'permission' => 'pdf_signer'],
            ['user_id' => 2, 'permission' => 'pdf_signer'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->routing->down();
        $this->migration->down();
        foreach (['workflow_events', 'completed_seals', 'seal_positions', 'document_signers', 'owner_workflows', 'documents',
            'user_permissions', 'team_members', 'teams', 'divisions', 'departments', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->removeDirectory($this->dataDirectory);
        putenv('PDF_SEAL_DATA_DIR');
        unset($_ENV['PDF_SEAL_DATA_DIR'], $_SERVER['PDF_SEAL_DATA_DIR']);

        parent::tearDown();
    }

    public function test_seal_profile_accepts_png_only_and_keeps_legacy_columns_in_sync(): void
    {
        $controller = new SealProfilesController;
        $invalid = $controller->update($this->upload(1, ['style' => 'round', 'centerText' => 'HR', 'color' => '#c62828'], 'not a png'));
        $this->assertSame(400, $invalid->getStatusCode());

        $saved = $controller->update($this->upload(1, [
            'style' => 'rect', 'orgText' => 'Giám đốc', 'centerText' => 'Nguyễn Văn An', 'subText' => 'Phòng HC', 'color' => '#1D4ED8',
        ], $this->png(60, 30)));
        $this->assertSame(200, $saved->getStatusCode(), json_encode($saved->getData(true), JSON_UNESCAPED_UNICODE));
        $profile = DB::table('user_seal_profiles')->where('user_id', 1)->first();
        $this->assertSame('rect', $profile->style);
        $this->assertEqualsWithDelta(2.0, (float) $profile->aspect, 0.001);
        $this->assertFileExists($profile->image_path);
        $user = DB::table('users')->find(1);
        $this->assertSame('NVA', $user->seal_text);
        $this->assertSame('#1d4ed8', $user->color);
    }

    public function test_holder_edits_own_seals_freely_until_forwarding_and_again_when_returned(): void
    {
        (new SealProfilesController)->update($this->upload(1, ['style' => 'round', 'centerText' => 'HR', 'color' => '#c62828'], $this->png(40, 40)));
        $documents = new DocumentsController;
        $documentId = $this->uploadDocument(1);

        // (4) Chưa chuyển tiếp: tài liệu nằm trong "Tài liệu của tôi", thêm/sửa/xóa dấu tùy ý, nhiều trang.
        $mine = $documents->mine($this->jsonAs(1, []))->getData(true);
        $this->assertSame([$documentId], array_column($mine['holding'], 'id'));
        $first = $this->addSeal(1, $documentId, 5);
        $second = $this->addSeal(1, $documentId, 2);
        $this->assertSame(200, $documents->updateSeal($this->jsonAs(1, $this->place(3)), (string) $documentId, (string) $second)->getStatusCode());
        $this->assertSame(200, $documents->deleteSeal($this->jsonAs(1, []), (string) $documentId, (string) $second)->getStatusCode());
        $seal = DB::table('document_seals')->find($first);
        $this->assertSame(5, (int) $seal->page);
        $this->assertFileExists($seal->image_path);
        $this->assertEqualsWithDelta((float) $seal->width, (float) $seal->height, 0.01);
        $this->assertSame(1, DB::table('document_seals')->where('document_id', $documentId)->count());

        // (1)(2) Chuyển cho người 2: người 1 không còn sửa được dấu của mình.
        $forward = $documents->forward($this->jsonAs(1, ['toUserId' => 2, 'note' => 'Nhờ anh đóng dấu']), (string) $documentId);
        $this->assertSame(200, $forward->getStatusCode(), json_encode($forward->getData(true), JSON_UNESCAPED_UNICODE));
        $this->assertSame(403, $documents->updateSeal($this->jsonAs(1, $this->place(1)), (string) $documentId, (string) $first)->getStatusCode());
        $this->assertSame(403, $documents->deleteSeal($this->jsonAs(1, []), (string) $documentId, (string) $first)->getStatusCode());
        $this->assertSame([$documentId], array_column($documents->mine($this->jsonAs(1, []))->getData(true)['sent'], 'id'));
        $this->assertSame([$documentId], array_column($documents->mine($this->jsonAs(2, []))->getData(true)['holding'], 'id'));

        // Người 2 thấy dấu người 1 nhưng không sửa được; đặt dấu của mình rồi chuyển lại.
        $detail = $documents->show($this->jsonAs(2, []), (string) $documentId)->getData(true);
        $this->assertFalse($detail['seals'][0]['editable']);
        $this->assertSame(403, $documents->updateSeal($this->jsonAs(2, $this->place(1)), (string) $documentId, (string) $first)->getStatusCode());
        $this->addSeal(2, $documentId, 1);
        $documents->forward($this->jsonAs(2, ['toUserId' => 1]), (string) $documentId);

        // (3) Được chuyển lại: người 1 sửa được dấu đã đóng của mình.
        $this->assertSame(200, $documents->updateSeal($this->jsonAs(1, $this->place(4)), (string) $documentId, (string) $first)->getStatusCode());
        $this->assertSame(4, (int) DB::table('document_seals')->find($first)->page);
        $detail = $documents->show($this->jsonAs(1, []), (string) $documentId)->getData(true);
        $editable = collect($detail['seals'])->pluck('editable', 'userId');
        $this->assertTrue($editable['1']);
        $this->assertFalse($editable['2']);

        // Hoàn tất: mọi dấu bị khóa, có trong danh sách đã đóng dấu của người tham gia.
        $this->assertSame(200, $documents->complete($this->jsonAs(1, []), (string) $documentId)->getStatusCode());
        $this->assertSame(409, $documents->updateSeal($this->jsonAs(1, $this->place(1)), (string) $documentId, (string) $first)->getStatusCode());
        $this->assertSame([$documentId], array_column($documents->completed($this->jsonAs(2, []))->getData(true), 'id'));
        $this->assertSame([], $documents->completed($this->jsonAs(3, []))->getData(true));
        $this->assertSame(404, $documents->show($this->jsonAs(3, []), (string) $documentId)->getStatusCode());
        $this->assertSame(404, $documents->sealImage($this->jsonAs(3, []), (string) $documentId, (string) $first)->getStatusCode());
        $this->assertSame(200, $documents->sealImage($this->jsonAs(2, []), (string) $documentId, (string) $first)->getStatusCode());
        $actions = array_column($documents->show($this->jsonAs(2, []), (string) $documentId)->getData(true)['routes'], 'action');
        $this->assertSame(['upload', 'forward', 'forward', 'complete'], $actions);
    }

    public function test_forward_and_complete_validation(): void
    {
        $documents = new DocumentsController;
        $documentId = $this->uploadDocument(1);

        $this->assertSame(400, $documents->forward($this->jsonAs(1, ['toUserId' => 1]), (string) $documentId)->getStatusCode());
        $this->assertSame(409, $documents->complete($this->jsonAs(1, []), (string) $documentId)->getStatusCode());
        $this->assertSame(400, $documents->addSeal($this->jsonAs(1, $this->place(0)), (string) $documentId)->getStatusCode());
        // Người không có quyền ký vẫn chuyển tiếp được nhưng không đặt dấu được.
        $documents->forward($this->jsonAs(1, ['toUserId' => 3]), (string) $documentId);
        $this->assertSame(403, $documents->addSeal($this->jsonAs(3, $this->place(1)), (string) $documentId)->getStatusCode());
        $this->assertSame(403, $documents->forward($this->jsonAs(1, ['toUserId' => 2]), (string) $documentId)->getStatusCode());
    }

    public function test_legacy_signer_chain_documents_are_converted(): void
    {
        $this->routing->down();
        $path = $this->dataDirectory.DIRECTORY_SEPARATOR.'old.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        $documentId = DB::table('documents')->insertGetId(['name' => 'cu.pdf', 'file_path' => $path, 'owner_id' => 1, 'completed_at' => '2026-11-03 10:00:00']);
        foreach ([1, 2] as $sequence => $userId) {
            DB::table('document_signers')->insert(['document_id' => $documentId, 'user_id' => $userId, 'sequence' => $sequence]);
            DB::table('seal_positions')->insert(['document_id' => $documentId, 'user_id' => $userId, 'page' => $sequence + 1, 'x' => 10, 'y' => 20, 'width' => 126, 'height' => 84, 'signed_at' => '2026-11-03 09:00:00']);
            DB::table('completed_seals')->insert(['document_id' => $documentId, 'user_id' => $userId, 'user_name' => "U{$userId}", 'seal_text' => 'AB', 'signature_name' => 'Sig', 'color' => '#123456', 'page' => $sequence + 1, 'x' => 10, 'y' => 20, 'width' => 126, 'height' => 84]);
        }
        $this->routing->up();

        $this->assertSame('completed', DB::table('document_workflows')->where('document_id', $documentId)->value('status'));
        $this->assertSame([1, 2], DB::table('document_seals')->where('document_id', $documentId)->orderBy('page')->pluck('page')->map(fn ($page) => (int) $page)->all());
        $this->assertSame([$documentId], array_column((new DocumentsController)->completed($this->jsonAs(2, []))->getData(true), 'id'));
    }

    private function uploadDocument(int $userId): int
    {
        $path = tempnam($this->dataDirectory, 'pdf');
        file_put_contents($path, "%PDF-1.4\n%test\n");
        $request = Request::create('/api/documents', 'POST', [], [], [
            'file' => new UploadedFile($path, 'hop-dong.pdf', 'application/pdf', null, true),
        ]);
        $request->attributes->set('workdesk_user', $this->user($userId));
        $response = (new DocumentsController)->upload($request);
        $this->assertSame(201, $response->getStatusCode(), json_encode($response->getData(true), JSON_UNESCAPED_UNICODE));

        return (int) $response->getData(true)['id'];
    }

    private function place(int $page): array
    {
        return ['page' => $page, 'x' => 100, 'y' => 150, 'width' => 120, 'height' => 80];
    }

    private function addSeal(int $userId, int $documentId, int $page): int
    {
        $response = (new DocumentsController)->addSeal($this->jsonAs($userId, $this->place($page)), (string) $documentId);
        $this->assertSame(201, $response->getStatusCode(), json_encode($response->getData(true), JSON_UNESCAPED_UNICODE));

        return (int) DB::table('document_seals')->where('document_id', $documentId)->where('user_id', $userId)->max('id');
    }

    private function user(int $id): object
    {
        return DB::table('users')->find($id);
    }

    private function jsonAs(int $userId, array $input): Request
    {
        $request = Request::create('/api', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($input));
        $request->attributes->set('workdesk_user', $this->user($userId));

        return $request;
    }

    private function upload(int $userId, array $fields, string $bytes): Request
    {
        $path = tempnam($this->dataDirectory, 'png');
        file_put_contents($path, $bytes);
        $request = Request::create('/api/seal-profile', 'POST', $fields, [], [
            'image' => new UploadedFile($path, 'seal.png', 'image/png', null, true),
        ]);
        $request->attributes->set('workdesk_user', $this->user($userId));

        return $request;
    }

    /** PNG RGBA tối thiểu (không cần GD). */
    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\xc8\x1e\x1e\xc8", $width);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            .$chunk('IDAT', gzcompress(str_repeat($row, $height)))
            .$chunk('IEND', '');
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
