<?php

namespace App\Http\Controllers;

use App\Support\SealImages;
use App\Support\WorkdeskBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thiết kế con dấu/chữ ký của tài khoản (Hồ sơ & bảo mật → Con dấu & chữ ký).
 * Trình duyệt dựng ảnh PNG từ thiết kế (hoặc từ ảnh dấu/chữ ký tải lên) và gửi lên;
 * máy chủ chỉ chấp nhận PNG hợp lệ.
 */
class SealProfilesController extends ApiController
{
    private const STYLES = ['round', 'square', 'rect', 'signature', 'image'];

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->summary($this->user($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $style = $request->input('style');
        $orgText = $this->text($request->input('orgText'), 120);
        $centerText = $this->text($request->input('centerText'), 40);
        $subText = $this->text($request->input('subText'), 120);
        $color = $request->input('color');
        if (! in_array($style, self::STYLES, true) || $orgText === null || $centerText === null || $subText === null
            || ! is_string($color) || ! preg_match('/^#[\da-f]{6}$/i', $color)) {
            return $this->error('Thông tin con dấu không hợp lệ.', 400);
        }
        if ($style !== 'image' && $centerText === '' && $subText === '' && $orgText === '') {
            return $this->error('Nhập ít nhất một dòng chữ cho con dấu.', 400);
        }
        $file = $request->file('image');
        if (! $file || ! $file->isValid()) {
            return $this->error('Thiếu ảnh con dấu đã dựng.', 400);
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $size = SealImages::pngSize($bytes);
        if (! $size) {
            return $this->error('Ảnh con dấu phải là PNG hợp lệ, tối đa 3 MB và 3000×3000 điểm ảnh.', 400);
        }
        $aspect = round(max(0.2, min(5, $size[0] / $size[1])), 4);

        $path = SealImages::storeProfileImage($bytes);
        $previous = SealImages::profile($user->id);
        try {
            DB::transaction(function () use ($user, $style, $orgText, $centerText, $subText, $color, $path, $aspect): void {
                DB::table('user_seal_profiles')->updateOrInsert(
                    ['user_id' => (int) $user->id],
                    [
                        'style' => $style,
                        'org_text' => $orgText,
                        'center_text' => $centerText,
                        'sub_text' => $subText,
                        'color' => strtolower($color),
                        'image_path' => $path,
                        'aspect' => $aspect,
                        'updated_at' => now(),
                    ],
                );
                // Giữ các cột cũ để tài liệu/kiểu dấu chữ trước đây vẫn hiển thị hợp lý.
                DB::table('users')->where('id', $user->id)->update([
                    'seal_text' => mb_strtoupper(mb_substr($this->initials($centerText ?: $orgText ?: (string) $user->name), 0, 3)),
                    'signature_name' => mb_substr($subText ?: $centerText ?: (string) $user->name, 0, 120),
                    'color' => strtolower($color),
                ]);
            });
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        if ($previous?->image_path && $previous->image_path !== $path && is_file($previous->image_path)) {
            @unlink($previous->image_path);
        }
        WorkdeskBroadcast::users([$user->id], 'pdf');
        $fresh = DB::table('users')->where('id', $user->id)->first();

        return response()->json([...$this->summary($fresh), 'account' => $this->publicUser($fresh)]);
    }

    /** Ảnh dấu hiện tại của chính mình (xem trước và khi đang đặt dấu). */
    public function image(Request $request)
    {
        $profile = SealImages::profile($this->user($request)->id);
        if (! $profile?->image_path || ! is_file($profile->image_path)) {
            return $this->error('Bạn chưa thiết kế con dấu.', 404);
        }

        return response()->file($profile->image_path, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=31536000']);
    }

    private function summary(object $user): array
    {
        $profile = SealImages::profile($user->id);

        return [
            'configured' => (bool) $profile,
            'style' => $profile->style ?? 'round',
            'orgText' => $profile->org_text ?? '',
            'centerText' => $profile->center_text ?? ($user->seal_text ?? ''),
            'subText' => $profile->sub_text ?? ($user->signature_name ?? ''),
            'color' => $profile->color ?? ($user->color ?? '#c62828'),
            'aspect' => (float) ($profile->aspect ?? 1.5),
            'imageUrl' => $profile?->image_path ? '/api/seal-profile/image?v='.strtotime((string) $profile->updated_at) : null,
            'updatedAt' => $profile->updated_at ?? null,
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return mb_strlen($value) > $max ? null : $value;
    }

    private function initials(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if (count($words) <= 1) {
            return mb_substr($text, 0, 3);
        }

        return implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 3)));
    }
}
