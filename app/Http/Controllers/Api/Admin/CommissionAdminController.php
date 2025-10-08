<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionAdminController extends Controller
{
    private const KEY = 'referral_default_commission_rate';

    // GET /api/admin/commission-settings
    public function index()
    {
        $row = DB::table('panel_settings')->where('key', self::KEY)->first();
        $val = $row ? (string)($row->value ?? '') : '';

        return response()->json([
            'status' => 'ok',
            'data'   => ['default_percent' => $val],
        ]);
    }

    // PUT /api/admin/commission-settings
    public function update(Request $req)
    {
        // اعتبارسنجی مرحله‌ای
        $raw = $req->input('default_percent', null);
        if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
            return response()->json(['status'=>'error','message'=>'مقدار درصد پیش فرض کمیسیون را وارد کنید'], 422);
        }
        if (!is_numeric($raw)) {
            return response()->json(['status'=>'error','message'=>'درصد نامعتبر است'], 422);
        }
        $percent = (float)$raw;
        if ($percent < 0 || $percent > 100) {
            return response()->json(['status'=>'error','message'=>'درصد باید بین 0 تا 100 باشد'], 422);
        }

        $changed = false;

        DB::transaction(function() use (&$changed, $percent) {
            $row = DB::table('panel_settings')->where('key', self::KEY)->first();
            if ($row) {
                // مقایسه عددی برای جلوگیری از تفاوت‌های ظاهری رشته‌ای
                if ((float)$row->value !== (float)$percent) {
                    DB::table('panel_settings')
                        ->where('id', $row->id)
                        ->update(['value' => (string)$percent, 'updated_at' => now()]);
                    $changed = true;
                }
            } else {
                DB::table('panel_settings')->insert([
                    'key'        => self::KEY,
                    'value'      => (string)$percent,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $changed = true;
            }
        });

        return response()->json([
            'status'  => 'ok',
            'changed' => $changed,
            'message' => $changed ? 'تغییرات با موفقیت ذخیره شد' : 'تغییری اعمال نشد',
        ]);
    }
}
