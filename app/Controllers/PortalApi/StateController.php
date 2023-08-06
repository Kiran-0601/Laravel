<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\State;

class StateController extends Controller
{
    use ResponseTrait;
    public function stateList($id)
    {
        try {
            $data = State::select('id', 'state_name')->where('country_id', $id)->get();

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while login user";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
