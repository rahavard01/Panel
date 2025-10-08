<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\PanelCardNumber;

class CardAdminController extends Controller
{
    // GET /api/admin/card-settings
    public function index()
    {
        $row = PanelCardNumber::orderByDesc('id')->first();

        return response()->json([
            'status' => 'ok',
            'data' => [
                'holder'      => (string)($row->name ?? ''),
                'card_number' => (string)($row->card ?? ''),
            ],
        ]);
    }

    // PUT /api/admin/card-settings
    public function update(Request $req)
    {
        $holder = trim((string)$req->input('holder', ''));
        $card   = preg_replace('/\D+/', '', (string)$req->input('card_number', ''));

        // اعتبـارسنجی مرحله‌ای برای توست‌ها
        if ($holder === '') {
            return response()->json(['status'=>'error','message'=>'نام صاحب کارت را وارد کنید'], 422);
        }
        if ($card === '') {
            return response()->json(['status'=>'error','message'=>'شماره کارت را وارد کنید'], 422);
        }
        if (strlen($card) !== 16) {
            return response()->json(['status'=>'error','message'=>'شماره کارت باید 16 رقم باشد'], 422);
        }

        $changed = false;

        DB::transaction(function() use (&$changed, $holder, $card) {
            $row = PanelCardNumber::orderByDesc('id')->first();
            if ($row) {
                $delta = [];
                if ((string)$row->name !== $holder) $delta['name'] = $holder;
                if ((string)$row->card !== $card)   $delta['card'] = $card;

                if (!empty($delta)) {
                    $row->update($delta);
                    $changed = true;
                }
            } else {
                PanelCardNumber::create([
                    'name' => $holder,
                    'card' => $card,
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
