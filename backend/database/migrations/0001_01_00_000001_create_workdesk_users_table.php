<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng nhân viên dùng chung với hệ thống LMS: các cột tiếng Việt (GioiTinh, NgayVaoLamViec...)
 * đến từ LMS, các cột name/seal_text/password_hash/role/org_level... là của Workdesk.
 * users.id là int (có dấu); mọi khóa ngoại trỏ tới users đều dùng kiểu int.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replaceLaravelDefaultUsersTable();

        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->integer('id', true);
            $table->string('user_code', 50)->nullable()->unique('user_code');
            $table->string('full_name')->nullable();
            $table->integer('timekeeping_code')->nullable();
            $table->string('timekeeping_name')->nullable();
            $table->integer('card_no')->nullable();
            $table->decimal('UserPassWord', 12, 2)->nullable();
            $table->integer('PhanQuyen')->nullable();
            $table->boolean('UserEnable')->nullable();
            $table->boolean('GioiTinh')->nullable();
            $table->dateTime('NgayVaoLamViec')->nullable();
            $table->decimal('ChucVu', 12, 2)->nullable();
            $table->dateTime('NgaySinh')->nullable();
            $table->decimal('NoiSinh', 12, 2)->nullable();
            $table->string('LoaiNhanVien')->nullable();
            $table->string('CMND')->nullable();
            $table->decimal('DienThoaiLienHe', 12, 2)->nullable();
            $table->string('Email', 254)->nullable()->unique('users_email_unique');
            $table->integer('NgayPhep')->nullable();
            $table->decimal('HinhAnh', 12, 2)->nullable();
            $table->integer('TienLuong')->nullable();
            $table->integer('LuongHopDong')->nullable();
            $table->string('DanToc')->nullable();
            $table->string('QuocTich')->nullable();
            $table->decimal('Skype', 12, 2)->nullable();
            $table->decimal('Yahoo', 12, 2)->nullable();
            $table->decimal('Facebook', 12, 2)->nullable();
            $table->decimal('PassWord', 12, 2)->nullable();
            $table->boolean('DangThamGiaBaoHiem')->nullable();
            $table->boolean('NghiViecTamThoi')->nullable();
            $table->boolean('TinhLuongTheo')->nullable();
            $table->boolean('SanPhamOrCongDoan')->nullable();
            $table->dateTime('NgayCap')->nullable();
            $table->decimal('NoiCap', 12, 2)->nullable();
            $table->dateTime('NgayKyHopDong')->nullable();
            $table->integer('ThoiHanHopDong')->nullable();
            $table->string('TrinhDo')->nullable();
            $table->decimal('DuongDanAnh', 12, 2)->nullable();
            $table->decimal('GhiChu', 12, 2)->nullable();
            $table->boolean('NhanVienMoi')->nullable();
            $table->string('MaCongTy')->nullable();
            $table->string('MaKhuVuc')->nullable();
            $table->string('MaPhongBan')->nullable();
            $table->decimal('MaChucVu', 12, 2)->nullable();

            $table->string('name', 120);
            $table->string('seal_text', 3);
            $table->string('signature_name', 120);
            $table->char('color', 7);
            $table->string('password_hash', 256)->nullable();
            $table->enum('role', ['admin', 'member'])->default('member');
            $table->boolean('active')->default(false);
            $table->dateTime('created_at')->useCurrent();
            $table->char('department_id', 36)->collation('utf8mb4_0900_ai_ci')->nullable();
            $table->enum('org_level', ['ceo', 'division_head', 'department_head', 'team_lead', 'employee'])
                ->default('employee');
        });
    }

    /**
     * Máy từng chạy migrate bản cũ có thể đã có bảng users mặc định của Laravel
     * (id bigint unsigned, không có user_code). Khóa ngoại của Workdesk dùng users.id
     * kiểu int, nên bảng đó phải được thay bằng bảng users của Workdesk.
     */
    private function replaceLaravelDefaultUsersTable(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'user_code')) {
            return;
        }

        if (DB::table('users')->exists()) {
            throw new \RuntimeException(
                'Bảng users hiện có là bảng mặc định của Laravel (id bigint unsigned, không có user_code) '
                .'và đã có dữ liệu, nên không thể tự thay thế. Nếu đây là máy chưa có dữ liệu thật, '
                .'hãy chạy "php artisan migrate:fresh"; nếu không, hãy sao lưu và chuyển dữ liệu users sang cấu trúc Workdesk trước.'
            );
        }

        Schema::disableForeignKeyConstraints();
        Schema::drop('users');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Không xóa: bảng users chứa toàn bộ nhân viên LMS. Trên máy phát triển, dùng
        // `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
