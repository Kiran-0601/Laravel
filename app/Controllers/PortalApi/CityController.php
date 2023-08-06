<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\City;

class CityController extends Controller
{
    use ResponseTrait;
    public function cityList($id)
    {
        try {
            $data = City::select('id', 'city_name')->where('state_id', $id)->get();

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while login user";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
