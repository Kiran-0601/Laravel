<?php

namespace App\Http\Controllers\PortalApi;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Designation;
use App\Traits\ResponseTrait;

class DesignationController extends Controller
{
    use ResponseTrait;
    public function designationList()
    {
        $data = Designation::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }
}
