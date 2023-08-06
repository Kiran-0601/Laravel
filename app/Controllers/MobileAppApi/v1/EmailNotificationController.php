<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\Config;
use Carbon\Carbon;
use Auth, Lang, DB, Log;
use App\Jobs\SendEmailJob;
use Illuminate\Http\Request;
use App\Notifications\PushNotifications;

class EmailNotificationController extends Controller
{
    use ResponseTrait;
    /* Cronjob setting entry in config table */
    public function changeValueInConfig(Request $request)
    {
        DB::beginTransaction();
        $inputs = $request->all();

        $query =[
            'value' => isset($inputs['punch_in_out_cron']) ? $inputs['punch_in_out_cron'] : null,
        ];
        Config::where('key', '=', 'punch_in_out_cron')->update($query);

        $dataa =[
            'value' => isset($inputs['every_day_leave_cron']) ? $inputs['every_day_leave_cron'] : null,
        ];

        Config::where('key', '=', 'every_day_leave_cron')->update($dataa);

        $query =[
            'value' => isset($inputs['systm_under_maintenance']) ? $inputs['systm_under_maintenance'] : null,
        ];

        Config::where('key', '=', 'systm_under_maintenance')->update($query);

        $punch_in_out_cron = Config::where('key','=','punch_in_out_cron')->value('value');
        $every_day_leave_cron = Config::where('key','=','every_day_leave_cron')->value('value');
        $systm_under_maintenance = Config::where('key','=','systm_under_maintenance')->value('value');

        $punch_in_out_cron = $punch_in_out_cron == 1 ? 1 : 0;
        $every_day_leave_cron = $every_day_leave_cron == 1 ? 1 : 0;
        $systm_under_maintenance = $systm_under_maintenance == 1 ? 1 : 0;

        $data['punch_in_out_cron'] = $punch_in_out_cron;
        $data['every_day_leave_cron'] = $every_day_leave_cron;
        $data['systm_under_maintenance'] = $systm_under_maintenance;

        DB::commit();

        return $this->sendSuccessResponse(Lang::get('messages.save'),200,$data);
    }

    /* get Cron Enable Disable Setting */
    public function getCronSetting()
    {
        try{
            $punch_in_out_cron = Config::where('key','=','punch_in_out_cron')->value('value');
            $every_day_leave_cron = Config::where('key','=','every_day_leave_cron')->value('value');
            $systm_under_maintenance = Config::where('key','=','systm_under_maintenance')->value('value');

            $punch_in_out_cron = $punch_in_out_cron == 1 ? 1 : 0;
            $every_day_leave_cron = $every_day_leave_cron == 1 ? 1 : 0;
            $systm_under_maintenance = $systm_under_maintenance == 1 ? 1 : 0;

            $data['punch_in_out_cron'] = $punch_in_out_cron;
            $data['every_day_leave_cron'] = $every_day_leave_cron;
            $data['systm_under_maintenance'] = $systm_under_maintenance;

            return $this->sendSuccessResponse(Lang::get('messages.list'),200,$data);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Comp Off Data List Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

}
