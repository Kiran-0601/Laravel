<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\Department;

class DepartmentController extends Controller
{
    use ResponseTrait;
    public function departmentList()
    {
        $data = Department::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }
}
