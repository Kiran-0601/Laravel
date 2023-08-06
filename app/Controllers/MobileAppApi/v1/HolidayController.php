<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class HolidayController extends Controller
{
    use ResponseTrait;

    public function holidayList(Request $request)
    {
        try
        {
            $year = $request->date ?? date('Y');
            $data = Holiday::whereYear('date', $year)->select('uuid', 'name','date', 'description', 'holiday_image')->get();
            foreach ($data as $list) {
                if($list['holiday_image'] != ''){
                    $list['holiday_image'] = env('STORAGE_URL').$list['holiday_image'];
                }
            }
            return $this->sendSuccessResponse(__('messages.success'),200,$data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while add holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
