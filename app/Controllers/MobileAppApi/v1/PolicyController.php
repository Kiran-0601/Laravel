<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\Policy;
use App\Validators\PolicyValidator;
use DB, Log, Lang;
use Exception;
use Illuminate\Http\Request;
use Storage;

class PolicyController extends Controller
{
    use ResponseTrait;
    private $policyValidator;

    function __construct()
    {
        $this->policyValidator = new PolicyValidator();
    }

    public function getPolicy()
    {
        try{
            $policyData = Policy::get();

            foreach ($policyData as $key => $value) {
                $value->file = env('STORAGE_URL') . str_replace('policies', 'temp', $value->file);
            }
            return $this->sendSuccessResponse(Lang::get('messages.success'),200,$policyData);
        }  catch (\Throwable $ex) {
            $logMessage = "Something went wrong while add holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function contentPage()
    {
        return view('contentpages.privacy-policy');
    }

    public function showPolicy()
    {
        try{
            $policyData = Policy::get();

            foreach ($policyData as $key => $value) {
                $value->file = env('STORAGE_URL') . str_replace('policies', 'temp', $value->file);
            }
            return $this->sendSuccessResponse(Lang::get('messages.success'),200,$policyData);
        }  catch (\Throwable $ex) {
            $logMessage = "Something went wrong while add holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
  
}
