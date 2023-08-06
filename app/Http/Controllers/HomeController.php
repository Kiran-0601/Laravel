<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        return view('home');
    }
    public function logout()
    {
        Auth::logout();
        return view('auth.login');
    }
    public function editProfile()
    {
        $countries = Country::get();
        return view('edit-profile', compact('countries'));
    }
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'lname' => 'required',
            'country' => 'required',
            'mobile' => 'required',
            'dob' => 'required',
            'gender' => 'required',
        ]);
        $user = User::find($request->id);
        // if user update profile-photo
        if($request->image){
            if($user->image) // check if image exists in database or not..
            {
                Storage::delete('/public/images/'.auth()->user()->image);  // old Image delete from folder
            }
            $croppedImageData = $request->input('cropped-image');
            $name = $request->file('image')->getClientOriginalName();
            $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $croppedImageData));
            Storage::disk('public')->put('images/' . $name, $imageData);     // save the new image in folder
            $user->image = $name;
        }
        $user->update($data);
        //dd("save");
        return redirect()->route('edit-profile')->with('success', 'User updated successfully');
    }
    public function changePassword()
    {
        return view('change-password');
    }
    public function saveChangePassword(Request $request)
    {
        $request->validate([
            'oldpassword' => 'required',
            'newpassword' => 'required|min:8',
            'cnfpassword' => 'required_with:newpassword|same:newpassword',
        ]);
        if (!(Hash::check($request->get('oldpassword'), Auth::user()->password))) {
            //The passwords matches
            return redirect()->back()->with("status", "Your Old password does not matches with the password you provided. Please try again.");
        }
        $user = $request->user();
        $user->password = Hash::make($request->get('newpassword'));
        if ($user->save()) {
            return redirect()->back()->with("status", "Password changed successfully !");
        } else {
            return redirect()->back()->with("status", "Password does not changed successfully !");
        }
    }
}
