<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Country;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view-admin-user')->only('index');
        $this->middleware('permission:add-admin-user')->only('create','store');
        $this->middleware('permission:edit-admin-user')->only('edit','update');
        $this->middleware('permission:delete-admin-user')->only('delete');
    }
    public function index(Request $request)
    {
        if ($request->ajax()) {

            $columns = array(
                0 => 'id',
                1 => 'name',
                2 => 'email',
                3 => 'role'
            );
            $query = User::select(['id', 'name', 'lname', 'email'])->where('user_type', 1)
                ->whereDoesntHave('roles', function ($query) {
                    $query->where('name', 'Admin');
                });
            $totalData = $query->count();
            $totalFiltered = $totalData;
            $start = $request->input('start');
            $length = $request->input('length');
            $order = $columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');

            if (!empty($request->input('search.value'))) {
                $searchValue = $request->input('search.value');
                $query->where(function ($query) use ($searchValue) {
                    $query->where('name', 'LIKE', "%{$searchValue}%")
                        ->orWhere('lname', 'LIKE', "%{$searchValue}%")
                        ->orWhere('email', 'LIKE', "%{$searchValue}%")
                        ->orWhereHas('roles', function ($query) use ($searchValue) {
                            $query->where('name', 'LIKE', "%{$searchValue}%");
                        });
                });
                $totalFiltered = $query->count();
            }
            $filters = $query->offset($start)
                ->limit($length)
                ->orderBy($order, $dir)
                ->get();

            $data = array();
            if (!empty($filters)) {
                foreach ($filters as $value) {
                    $nestedData['id'] = $value->id;
                    $name = $value->name . ' ' . $value->lname;
                    $nestedData['name'] = $name;
                    $nestedData['lname'] = $value->lname;
                    $nestedData['email'] = $value->email;
                    $nestedData['role'] = $value->getRoleNames();

                    // $actionButtons
                    $actionButtons = "";
                    if (auth()->user()->can('edit-admin-user')) {
                        $actionButtons .= '<a href="' . route('admin-user.edit', ['id' => $value->id]) . '">  Edit  </a>';
                    }
                    if (auth()->user()->can('delete-admin-user')) {
                        $actionButtons .= '<a class="delete-btn" data-id="' . $value->id . '"> Delete</a>';
                    }
                    $nestedData['actions'] = $actionButtons;
                    $data[] = $nestedData;
                }
            }
            $jsonData = [
                'draw' => $request->input('draw'),
                'recordsTotal' => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data' => $data,
            ];
            return response()->json($jsonData);
        }
        return view('admin.admin-users.home');
    }
    public function create()
    {
        $roles = Role::whereNotIn('name', ['Admin'])->get();
        $countries = Country::get();
        return view('admin.admin-users.add',compact('countries','roles'));
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'lname' => 'required',
            'country' => 'required',
            'mobile' => 'required',
            'dob' => 'required',
            'email' => ['required', 'email', 'unique:users'],
            'gender' => 'required',
            'role' => 'required',
        ]);
        $data['user_type'] = 1;
        $data['status'] = 1;
        $data['password'] = Hash::make("admin@123");
        $data['email_verified_at'] = now();
        
        User::create($data)->assignRole($request->role);
        //dd($user->getRoleNames());
        return redirect()->back()->with('status', 'Admin User Added successfully');
    }
    public function edit($id)
    {
        $countries = Country::get();
        $roles = Role::whereNotIn('name', ['Admin'])->get();
        $data = User::find($id);
        return view('admin.admin-users.edit',compact('data','roles','countries'));
    }
    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'lname' => 'required',
            'country' => 'required',
            'mobile' => 'required',
            'dob' => 'required',
            'email' => 'required|email|unique:users,email,' . $request->id,
            'gender' => 'required',
            'role' => 'required',
        ]);
        $user = User::find($request->id);
        $user->update($request->except('role'));
        $user->syncRoles($request->role);       
        //dd("save");
        return redirect()->route('admin-user.edit', $request->id)->with('status', 'Admin User updated successfully');
    }
    public function delete(Request $request)
    {
        $id = $request->id;
        User::destroy($id);
        return response()->json(['success' => true]);
    }
}
