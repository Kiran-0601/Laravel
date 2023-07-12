<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Storage;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    //protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showRegistrationForm()
    {
        $countries = Country::get();
        //dd($countries);
        return view('auth.register',compact('countries'));
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'lname' => ['required', 'string', 'max:255'],
            'country' => ['required'],
            'mobile' => ['required'],
            'dob' => ['required'],
            'gender' => ['required'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
            'address' => ['required'],
            'image' => ['required', 'image', 'mimes:jpeg,png,gif', 'max:2048'],
        ],);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data)
    {
        $croppedImageData = $data['cropped-image'];       // Get the cropped image data from the hidden field
        $name = $data['image']->getClientOriginalName();   // Get the name of the uploaded image file
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $croppedImageData));
        Storage::disk('public')->put('images/' . $name, $imageData);     // save the image in folder
        
        return User::create([
            'name' => $data['name'],
            'lname' => $data['lname'],
            'country' => $data['country'],
            'mobile' => $data['mobile'],
            'dob' => $data['dob'],
            'gender' => $data['gender'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'user_type' => "2",
            'status' => "1",
            'image' => $name,
        ]);
    }
    public function register(Request $request)
    {
        $this->validator($request->all())->validate();

        event(new Registered($user = $this->create($request->all())));
        
        return redirect()->route('login')->with('status', 'Register Successfully');
    }  
   
}
