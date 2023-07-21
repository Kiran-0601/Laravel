<?php

namespace App\Http\Controllers\API;

use App\Models\User;
use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Country;

class AuthController extends Controller
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
    public function register(Request $request)
    {
        try {
            // Validate the request data
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'lname' => ['required', 'string', 'max:255'],
                'country' => ['required'],
                'mobile' => ['required'],
                'dob' => ['required'],
                'gender' => ['required'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'password_confirmation' => ['required'],
                'addline1' => ['required'],
                'city' => ['required'],
                'pincode' => ['required'],
                'image' => ['required', 'image', 'mimes:jpeg,png,gif', 'max:2048'],
            ]);
           
            $validatedData = $validator->validate();
            if ($validator->fails()) {
                return $this->sendFailResponse($validator->errors()->first(), 422);
            }
            
            DB::beginTransaction();
            
            $name = $request->image->getClientOriginalName();
            $validatedData['image']->storeAs('images', $name, 'public');
            $user = User::create([
                'name' => $validatedData['name'],
                'lname' => $validatedData['lname'],
                'mobile' => $validatedData['mobile'],
                'dob' => $validatedData['dob'],
                'gender' => $validatedData['gender'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
                'user_type' => "2",
                'status' => "1",
                'image' => $name,
            ]);

            $user_id = $user->id;
            $countryId = null;
            if(!empty($validatedData['country'])){
                $country = Country::where('name', $validatedData['country'])->first(['id']);
                if(!empty($country)){
                    $countryId = $country->id;
                }
            }
            // Create a new address record and associate it with the user
            $address = new Address([
                'user_id' => $user_id,
                'addline1' => $validatedData['addline1'],
                'country_id' => !empty($countryId) ? $countryId : null,
                'city' => $validatedData['city'],
                'pincode' =>  $validatedData['pincode'],
            ]);
            $user->addresses()->save($address);
            DB::commit();
    
            //return response()->json(['message' => 'Registration successful'], 200);
            return $this->sendSuccessResponse('Registration successful', 200, $validatedData);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendServerFailResponse('An error occurred during registration', 500);
        }
    }
}