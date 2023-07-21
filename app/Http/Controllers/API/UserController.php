<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Log;
use App\Models\Address;
use App\Models\Country;

class UserController extends Controller
{
    public function sendSuccessResponse($message = "", $code = 200, $data = null)
    {
        $jsonData = array();
        $jsonData['error'] = false;
        $jsonData['status'] = $code;
        $jsonData['message'] = $message;
        $jsonData['result'] = $data;
        
        // Add a success key and set it to true
        $jsonData['success'] = true;
        return response()->json($jsonData, $code);
    }
    public function sendFailResponse($message = "", $code = 422, $data = null, $logMessage = "")
    {
        //show on log page
        if(!empty($logMessage)) {
            Log::info($logMessage);
            Log::error($data);
        }

        $jsonData = array();
        $jsonData['error'] = true;
        $jsonData['status'] = $code;
        $jsonData['message'] = $message;
        $jsonData['result'] = $data;
        return response()->json($jsonData, $code);
    }
    public function sendServerFailResponse($message = "", $code = 500, $data = null,$logMessage = "")
    {
        //show on log page
        if(!empty($logMessage)) {
            Log::info($logMessage);
            Log::error($data);
        }
        $jsonData = array();
        $jsonData['error'] = true;
        $jsonData['status'] = $code;
        $jsonData['message'] = $message;
        $jsonData['result'] = null;
        return response()->json($jsonData, $code);
    }
    public function index()
    {
        $user = User::with('addresses.country')->get();
        $user->each(function ($item) {
            $item->image = asset('storage/images/' . $item->image);
        });
        
        return $this->sendSuccessResponse(__('messages.success'), 200, $user);
    }
    public function show($id)
    {
        try {
            $user = User::with('addresses.country')->where('id', $id)->first();
            $user->image = asset('storage/images/' . $user->image);
            $user->addresses->first()->country_id = $user->addresses->first()->country->name;  // Display Country Name in Country_id field
            
            return $this->sendSuccessResponse(__('messages.success'), 200, $user);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get User detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function update(Request $request,$id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required',
                'lname' => 'required',
                'country' => 'required',
                'image' => 'nullable',
            ]);
            
            if ($validator->fails()) {
                return $this->sendFailResponse($validator->errors()->first(), 422);
            }
            DB::beginTransaction();
            $user = User::where('id', $id)->first();
            
            if($request->image){
                if($user->image) // check if image exists in database or not..
                {
                    Storage::delete('/public/images/'.$user->image);  // old Image delete from folder
                }
                $name = $request->file('image')->getClientOriginalName();
                $request->image->storeAs('images', $name, 'public');   // save the new image in folder
                $user->image = $name;
            }
            if($request->password)
            {
                $user->password = Hash::make($request->password);
            }
            $data = $user->update($request->except('image'));
            if($request->country){
                $country = Country::where('name', $request->country)->first(['id']);
                if(!empty($country)){
                    //$countryId = $country->id;
                    $user->addresses()->update(['country_id' => $country->id]);
                }
            }
            $addressData = $request->only([
                'addline1',
                'addline2',
                'city',
                'pincode',
            ]);
            if (array_filter($addressData) !== []) {
                $user->addresses()->update($addressData);
            }
            DB::commit();
            return $this->sendSuccessResponse('Data Updated', 200, $data);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendServerFailResponse('An error occurred during registration', 500);
        }
    }
    public function delete($id)
    {
        DB::beginTransaction();
        try {
            User::where('id', $id)->delete();
            Address::where('user_id', $id)->delete();
            DB::commit();
            return $this->sendSuccessResponse(__('messages.employee_deleted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while send invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
