<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Country;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {

            $columns = array(
                0 => 'id',
                1 => 'name',
                2 => 'email',
                3 => 'gender'
            );
            $query = User::select(['id', 'name', 'lname', 'email', 'gender'])->where('user_type', 2);

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
    
                    // $actionButtons
                    $actionButtons = "";
                    $actionButtons .= '<a href="' . route('users.view', ['id' => $value->id]) . '">View |</a>';
                    $actionButtons .= '<a href="' . route('users.edit', ['id' => $value->id]) . '"> Edit |</a>';
                    $actionButtons .= '<a class="delete-btn" data-id="' . $value->id . '"> Delete</a>';
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
        $data = User::find($id);
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
}
