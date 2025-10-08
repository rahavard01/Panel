<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    /**
     * GET /api/settings/link-parts
     * از v2_settings بر اساس name می‌خواند:
     * - subscribe_url  → part1
     * - subscribe_path → part2
     * اگر هر کدام نبود، پیام متناسب برمی‌گرداند (422).
     */
    public function linkParts()
    {
        // فقط همین دو کلید را از ستون name بخوان
        $rows = DB::table('v2_settings')
            ->whereIn('name', ['subscribe_url', 'subscribe_path'])
            ->pluck('value', 'name');

        $part1 = trim((string)($rows['subscribe_url']  ?? '')); // مثلاً: https://example.com
        $part2 = trim((string)($rows['subscribe_path'] ?? '')); // مثلاً: s

        if ($part1 === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'مقدار subscribe_url را در تنظیمات xboard وارد کنید',
            ], 422);
        }

        if ($part2 === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'مقدار subscribe_path را در تنظیمات xboard وارد کنید',
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'part1' => $part1,
                'part2' => $part2,
            ],
        ], 200);
    }
}
