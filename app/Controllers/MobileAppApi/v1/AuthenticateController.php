<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Models\EntityType;
use App\Models\DeviceDetail;
use App\Validators\UserValidator;
use Illuminate\Http\Request;
use Hash, JWTAuth, Lang, Response, Auth, Log, DB;
use App\Traits\ResponseTrait;

class AuthenticateController extends Controller
{
    private $userValidator;
    use ResponseTrait;
    /**
     * User Authenticate
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    public function __construct()
    {
        $this->userValidator = new UserValidator();
    }
    public function authenticate(Request $request)
    {
        try{      
            DB::beginTransaction();
            $inputs = $request->all();
            //validate request start
            $userLoginValidate = $this->userValidator->validateUserLogin($request);
            if ($userLoginValidate->fails()) {
                return $this->sendFailResponse($userLoginValidate->errors()->first(), 422);
            }
            //validate request end

            $email = $request->email;
            $password = $request->password;

            //check credential as per its data
            $credentials = $this->credentials($email, $password);
         
            if (!Auth::attempt($credentials)) {
                return $this->sendFailResponse(__('messages.login_fail'), 422);
            }
            $userInfo = Auth::user();
            if ($userInfo->is_active == false) {
                return $this->sendFailResponse(__('messages.inactive_account'), 422);
            }
          
            $userInfo = $this->getUserRolePermissions($userInfo);
            User::where('id', $userInfo->id)->update(['last_login_time' => now()]);
            $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;
            
            $deviceData = DeviceDetail::where(["user_id" => $userInfo->id, "device_id" => $inputs["device_id"], "device_type" => $inputs["device_type"]])->first();
            
            if (empty($deviceData)) {
                DeviceDetail::create([
                    "user_id" => $userInfo->id,
                    "device_type" => $inputs["device_type"],
                    "device_id" => $inputs["device_id"],
                    "device_token" => $inputs["device_token"]
                ]);
            } else {
                DeviceDetail::where("id", $deviceData->id)
                ->update([
                    "device_token" => $inputs["device_token"]
                ]);
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.login_success'), 200, $userInfo);
        } catch (\Exception $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while getting app version";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    //User login with username, email, mobile 
    protected function credentials($username = "", $password = "")
    {
        if (is_numeric($username)) {
            return ['mobile' => $username, 'password' => $password];
        } elseif (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $username, 'password' => $password];
        }
        return ['username' => $username, 'password' => $password];
    }

    /**
     * User Logout
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logOut(Request $request)
    {
        try {
            if (!Auth::check()) {
                return $this->sendFailResponse(__('messages.logout_error'), 200);
            }
            //remove existing token and logout user 
            Auth::user()->token()->revoke();

            return $this->sendSuccessResponse(__('messages.logout_success'), 200);
        } catch (\Exception $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while getting app version";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
