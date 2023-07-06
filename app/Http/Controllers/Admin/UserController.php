<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $users = User::select(['id', 'name', 'lname', 'email', 'gender'])->where('user_type', 2)->get();
            return response()->json(['data' => $users]);
        }
        return view('admin.users.home');
    }
    public function view($id)
    {
        $data = User::find($id);
        return view('admin.users.view', compact('data'));
    }
    public function edit($id)
    {
        $data = User::find($id);
        return view('admin.users.edit', compact('data'));
    }
    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'lname' => 'required',
            'country' => 'required',
            'mobile' => 'required',
            'dob' => 'required',
            'gender' => 'required',
        ]);
        $user = User::find($request->id);
        $user->update($request->all());
        //dd("save");
        return redirect()->route('users.edit', $request->id)->with('success', 'User updated successfully');
    }
    public function delete($id)
    {
        User::destroy($id);
        return redirect()->route('users')->with('success', 'User Deleted successfully');
    }
}
