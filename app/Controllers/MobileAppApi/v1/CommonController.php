<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Log, Lang, DB, Auth;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\User;
use Carbon\Carbon;
use Storage;

class CommonController extends Controller
{
    use ResponseTrait;
  
    public function getProjectManager()
    {
        try{
            $data = [];
            $users = User::all();
            $permission = Permission::where('name', 'create_project')->first();
            foreach ($users as $user) {
                if ($user->hasPermissionTo($permission->id)) {
                    $data[] = ["id" => $user->entity_id, "name" => $user->display_name, "email" => $user->email];
                }
            }
            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        }
        catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update leave type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
