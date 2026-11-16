<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Thêm các cột hồ sơ nhân sự lấy từ file "Danh sách công nhân viên" (sheet VN staff 2026).
 *
 * Chỉ THÊM cột mới (nullable), không sửa/xóa cột hay dữ liệu cũ. Thông tin đã có cột tương ứng
 * trong bảng users (NgaySinh, NgayVaoLamViec, CMND, NgayCap, NgayKyHopDong, DanToc, TrinhDo,
 * GioiTinh) được ghi vào cột cũ bằng lệnh `php artisan workdesk:import-staff-file`, không tạo cột trùng.
 * NoiSinh, NoiCap, ChucVu, DienThoaiLienHe đã có nhưng đang là kiểu số (decimal) nên không chứa được
 * chữ/số điện thoại có số 0 đầu; vì không được sửa cột cũ, các thông tin này dùng cột mới bên dưới.
 */
return new class extends Migration
{
    /** cột => [kiểu, độ dài] */
    private const COLUMNS = [
        'department_name' => ['string', 100],     // Dept
        'position' => ['string', 100],            // Postion
        'contract_term' => ['string', 100],       // Contract time
        'id_issue_place' => ['string', 255],      // Place issue
        'address' => ['string', 500],             // Address
        'place_of_birth' => ['string', 255],      // Place of birth
        'pit_code' => ['string', 20],             // ID PIT (mã số thuế TNCN)
        'bank_account' => ['string', 30],         // ATM
        'bank_name' => ['string', 255],           // Ngân Hàng
        'phone' => ['string', 30],                // Phone number
        'missing_documents' => ['string', 500],   // Hồ sơ cần bổ sung
        'sap_id' => ['string', 20],               // SAP ID
        'contract_appendix_no' => ['string', 50], // Số phụ lục HĐ
        'contract_no' => ['string', 50],          // Số HĐ
        'hdbank_atm_id' => ['string', 30],        // ID ATM (HD bank)
        'info_change_note' => ['string', 255],    // Thông tin thay đổi
        'old_id_number' => ['string', 20],        // ID/Hộ chiếu cũ 2023
        'old_id_issue_date' => ['date', null],    // Date issue (cũ)
        'old_id_issue_place' => ['string', 255],  // Place issue (cũ)
        'old_address' => ['string', 500],         // Địa chỉ cũ trước 1/7/2025
        'old_place_of_birth' => ['string', 500],  // Nơi sinh cũ trước 1/7/2025
    ];

    public function up(): void
    {
        $missing = array_filter(
            array_keys(self::COLUMNS),
            fn (string $column): bool => ! Schema::hasColumn('users', $column),
        );
        if (! $missing) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($missing): void {
            foreach ($missing as $column) {
                [$type, $length] = self::COLUMNS[$column];
                ($type === 'date' ? $table->date($column) : $table->string($column, $length))->nullable();
            }
        });
    }

    public function down(): void
    {
        // Không xóa: các cột chứa dữ liệu nhân sự đã nhập. Trên máy phát triển, dùng
        // `php artisan migrate:fresh` để làm lại từ đầu.
    }
};
