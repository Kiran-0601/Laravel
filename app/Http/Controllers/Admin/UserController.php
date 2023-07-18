<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Country;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view-user')->only('index','view');
        $this->middleware('permission:edit-user')->only('edit','update','updateStatus');
        $this->middleware('permission:delete-user')->only('delete');
    }
    public function index(Request $request)
    {
        if ($request->ajax()) {

            $columns = array(
                0 => 'id',
                1 => 'name',
                2 => 'email',
                3 => 'gender',
                4 => 'status'
            );
            $query = User::select(['id', 'name', 'lname', 'email', 'gender', 'status'])->where('user_type', 2);

            $totalData = $query->count();
            $totalFiltered = $totalData;
            $start = $request->input('start');
            $length = $request->input('length');
            $order = $columns[$request->input('order.0.column')];
            $dir = $request->input('order.0.dir');

            if (!empty($request->input('search.value'))) {
                $searchValue = $request->input('search.value');
                $query->where(function ($qry) use ($searchValue) {
                    $qry->where('name', 'LIKE', "%{$searchValue}%")
                        ->orWhere('lname', 'LIKE', "%{$searchValue}%")
                        ->orWhere('email', 'LIKE', "%{$searchValue}%")
                        ->orWhere('gender', 'LIKE', "%{$searchValue}%");
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
                    $nestedData['name'] = $value->name;
                    $nestedData['lname'] = $value->lname;
                    $nestedData['email'] = $value->email;
                    $nestedData['gender'] = $value->gender;

                    $toggleSwitch = "";
                    $checked = ($value->status == '1') ? 'checked' : '';

                    if (auth()->user()->can('edit-user')) {
                        $toggleSwitch .= '<div class="form-switch"><input class="form-check-input toggle-switch" type="checkbox" role="switch" data-id="' . $value->id . '" ' . $checked . '></div>';
                    }
                    elseif (auth()->user()->can('view-user')) {
                        $toggleSwitch .= '<div class="form-switch"><input class="form-check-input toggle-switch" type="checkbox" role="switch" data-id="' . $value->id . '" ' . $checked . ' disabled></div>';
                    }
                    $nestedData['status'] = $toggleSwitch;

                    // $actionButtons
                    $actionButtons = "";
                    if (auth()->user()->can('view-user')) {
                        $actionButtons .= '<a href="' . route('users.view', ['id' => $value->id]) . '">View  </a>';
                    }
                    if (auth()->user()->can('edit-user')) {
                        $actionButtons .= '<a href="' . route('users.edit', ['id' => $value->id]) . '">  Edit  </a>';
                    }
                    if (auth()->user()->can('delete-user')) {
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
        return view('admin.users.home');
    }
    public function view($id)
    {
        $data = User::with('addresses')->find($id);
        //dd($data);
        return view('admin.users.view', compact('data'));
    }
    public function edit($id)
    {
        $data = User::find($id);
        $countries = Country::get();
        //dd($countries);
        return view('admin.users.edit', compact('data', 'countries'));
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
        return response()->json(['success' => true]);
    }
    public function updateStatus(Request $request){

        User::find($request->userId)->update(['status' => $request->status]);
        $html = '';
        $html .= '<div id="alertMessage" class="alert alert-success message-container mt-5 fade show position-fixed top-0 end-0" role="alert">
        User Status Updated successfully !!! &nbsp;&nbsp;  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>';
        return response()->json(['success' => true,'Message' => $html,'status' => "Updated"]);
        
    }
}
