<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Models\Country;

class CountryController extends Controller
{
    use ResponseTrait;
    public function countryList()
    {
        $data = Country::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }
}
