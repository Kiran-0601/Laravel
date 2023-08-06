<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\User;
use App\Models\Employee;
use DB, Log, Lang, Auth;
use Exception;
use Illuminate\Http\Request;
use App\Jobs\SendEmailJob;

class NotificationController extends Controller
{
    use ResponseTrait;

    public function notification(){
        try{
            $user =Auth::user();
            $userInfo = User::select('id')->find($user->id);
            $notificationList = $userInfo->notifications()->limit(30)->get();            
            $userInfo->unreadNotifications->markAsRead(); //read all notification
            
            return $this->sendSuccessResponse(Lang::get('messages.notification.get'), 200, $notificationList);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Notification Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'), 500);
        }
    }

    public function delete(){
        try{
            $user = Auth::user();
            $userInfo = User::select('id')->find($user->id);
            $userInfo->notifications()->delete();

            return $this->sendSuccessResponse(Lang::get('messages.notification.delete'), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Notification Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'), 500);
        }
    }
}
