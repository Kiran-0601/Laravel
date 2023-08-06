<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\AppVersion;
use Auth, Lang, DB, Log;

class AppVersionController extends Controller
{
    use ResponseTrait;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getVersion()
    {
        try{
            $appVersion = AppVersion::where('is_active',1)->select('android','ios','description','created_at')->first();
            return $this->sendSuccessResponse(__('messages.success'), 200, $appVersion);
        } catch (\Exception $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while getting app version";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
